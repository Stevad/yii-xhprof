<?php

/**
 * XHProf application component for Yii Framework 1.x.
 * Uses original XHProf UI to display results.
 *
 * Designed to profile application from onBeforeRequest to onEndRequest events. You can also manually start and stop
 * profiler in any place of your code. By default component save last 25 reports. You can see then in bundled debug
 * panel for Yii2Debug extension (https://github.com/zhuravljov/yii2-debug). All other reports are available by default
 * in XHProf UI (e.g. http://some.path.to/xhprof_html)
 *
 * @author Vadym Stepanov <vadim.stepanov.ua@gmail.com>
 */
class XHProfComponent extends CApplicationComponent
{
    /**
     * Enable/disable component in Yii
     * @var bool
     */
    public $enabled = true;

    /**
     * Path alias to directory with reports file
     * @var string
     */
    public $reportPathAlias = 'application.runtime.xhprof';

    /**
     * How many reports to store in history file
     * @var integer
     */
    public $maxReportsCount = 25;

    /**
     * Set true to manually create instance of XHProf object and start profiling. Disabled by default, profile
     * is started on 'onBeginRequest' event
     * @var bool
     */
    public $manualStart = false;

    /**
     * Set true to manually stop profiling. Disabled by default, profile is stopped on 'onEndRequest' event.
     * @var bool
     */
    public $manualStop = false;

    /**
     * Force terminate profile process on 'onEndRequest' event if it is still running with enabled manual stop
     * @var bool
     */
    public $forceStop = true;

    /**
     * Set value to trigger profiling only by specified GET param with any value
     * @var string
     */
    public $triggerGetParam;

    /**
     * If this component is used without yii2-debug extension, set true to show overlay with links to report and
     * callgraph. Otherwise, set false and add panel to yii2-debug (see readme for more details).
     * @var bool
     */
    public $showOverlay = true;

    /**
     * Path alias to the 'xhprof_lib' directory. If not set, value of $libPath will be used instead
     * @var string
     */
    public $libPathAlias;

    /**
     * Direct filesystem path to the 'xhprof_lib' directory
     * @var string
     */
    public $libPath;

    /**
     * URL path to XHProf html reporting files without leading slash
     * @var string
     */
    public $htmlReportBaseUrl = '/xhprof_html';

    /**
     * Enable/disable flag XHPROF_FLAGS_NO_BUILTINS (see http://php.net/manual/ru/xhprof.constants.php)
     * @var bool
     */
    public $flagNoBuiltins = true;

    /**
     * Enable/disable flag XHPROF_FLAGS_CPU (see http://php.net/manual/ru/xhprof.constants.php)
     * Default: false. Reason - some overhead in calculation on linux OS
     * @var bool
     */
    public $flagCpu = false;

    /**
     * Enable/disable flag XHPROF_FLAGS_MEMORY (see http://php.net/manual/ru/xhprof.constants.php)
     * @var bool
     */
    public $flagMemory = true;

    /**
     * List of routes to not run xhprof on.
     * @var array
     */
    public $blacklistedRoutes = array('debug*');

    /**
     * Current report details
     * @var array
     */
    private $reportInfo;

    /**
     * Path to the temporary directory with reports
     * @var string
     */
    private $reportSavePath;

    /**
     * Initialize component and check path to xhprof library files. Start profiling and add overlay (if allowed
     * by configuration).
     * @throws CException
     */
    public function init()
    {
        parent::init();

        Yii::setPathOfAlias('yii-xhprof', __DIR__);
        Yii::app()->setImport(array('yii-xhprof.*'));

        if (!$this->enabled
            || ($this->triggerGetParam !== null && Yii::app()->request->getQuery($this->triggerGetParam) === null)
            || $this->isRouteBlacklisted()
        ) {
            return;
        }

        if (empty($this->libPath) && empty($this->libPathAlias)) {
            throw new CException('Both libPath and libPathAlias cannot be empty. Provide at least one of the value');
        }

        if (!$this->manualStart) {
            Yii::app()->attachEventHandler('onBeginRequest', array($this, 'beginProfiling'));
        }

        if ($this->showOverlay && !Yii::app()->request->isAjaxRequest) {
            $this->initOverlay();
            Yii::app()->attachEventHandler('onEndRequest', array($this, 'appendResultsOverlay'));
        }

        Yii::app()->attachEventHandler('onEndRequest', array($this, 'stopProfiling'));
    }

    /**
     * Get if component enabled and xhprof profiler is currently started
     * @return bool
     */
    public function isActive()
    {
        return $this->enabled && XHProf::getInstance()->isStarted();
    }

    /**
     * Configure XHProf instance and start profiling
     */
    public function beginProfiling()
    {
        $libPath = $this->libPath;
        if (!empty($this->libPathAlias)) {
            $libPath = Yii::getPathOfAlias($this->libPathAlias);
        }

        XHProf::getInstance()->configure(array(
            'flagNoBuiltins' => $this->flagNoBuiltins,
            'flagCpu' => $this->flagCpu,
            'flagMemory' => $this->flagMemory,
            'runNamespace' => Yii::app()->id,
            'libPath' => $libPath,
            'htmlUrlPath' => $this->getReportBaseUrl()
        ));

        XHProf::getInstance()->run();
    }

    /**
     * Stop profiling and save report
     */
    public function stopProfiling()
    {
        $XHProf = XHProf::getInstance();

        if ($XHProf->isStarted() && $XHProf->getStatus() === XHProf::STATUS_RUNNING
            && (!$this->manualStop || ($this->manualStop && $this->forceStop))
        ) {
            $XHProf->stop();
        }

        if ($this->isActive()) {
            $this->saveReport();
        }
    }

    /**
     * Add code to display own overlay with links to report and callgraph for current profile run
     */
    public function appendResultsOverlay()
    {
        $XHProf = XHProf::getInstance();
        if (!$XHProf->isStarted()) {
            return;
        }

        $data = $this->getReportInfo();
        $reportUrl = $XHProf->getReportUrl($data['runId'], $data['ns']);
        $callgraphUrl = $XHProf->getCallgraphUrl($data['runId'], $data['ns']);

        // write direct HTML because we cannot use clientScript
        echo <<<EOD
<script type="text/javascript">
(function() {
    var overlay = document.createElement('div');
    overlay.setAttribute('id', 'xhprof-overlay');
    overlay.innerHTML = '<div class="xhprof-header">XHProf</div><a href="{$reportUrl}" target="_blank">Report</a><a href="{$callgraphUrl}" target="_blank">Callgraph</a>';
    document.getElementsByTagName('body')[0].appendChild(overlay);
})();
</script>
EOD;
    }

    /**
     * Get reports save path
     * @return string
     */
    public function getReportSavePath()
    {
        if ($this->reportSavePath === null) {
            if ($this->reportPathAlias === null) {
                $path = Yii::app()->getRuntimePath() . '/xhprof';
            } else {
                $path = Yii::getPathOfAlias($this->reportPathAlias);
            }

            if (!is_dir($path)) {
                mkdir($path);
            }

            $this->reportSavePath = $path;
        }

        return $this->reportSavePath;
    }

    /**
     * Get report details for current profiling process. Info consists of:
     * - unique run identifier (runId)
     * - namespace for run (ns, current application ID by default)
     * - requested URL (url)
     * - time of request (time)
     * @return array key-valued list
     */
    public function getReportInfo()
    {
        if (!$this->isActive()) {
            return array(
                'enabled' => false,
                'runId' => null,
                'ns' => null,
                'url' => null,
                'time' => null
            );
        }

        if ($this->reportInfo === null) {
            $request = Yii::app()->getRequest();
            $this->reportInfo = array(
                'enabled' => true,
                'runId' => XHProf::getInstance()->getRunId(),
                'ns' => XHProf::getInstance()->getRunNamespace(),
                'url' => $request->getHostInfo() . $request->getUrl(),
                'time' => microtime(true)
            );
        }

        return $this->reportInfo;
    }

    /**
     * Get base URL part to the XHProf UI
     * @return string
     */
    public function getReportBaseUrl()
    {
        if (strpos($this->htmlReportBaseUrl, '://') === false) {
            return Yii::app()->getRequest()->getBaseUrl(true) . $this->htmlReportBaseUrl;
        }

        return $this->htmlReportBaseUrl;
    }

    /**
     * Load list of previous reports from JSON file
     * @return array
     */
    public function loadReports()
    {
        $reportsFile = "{$this->getReportSavePath()}/reports.json";
        $reports = array();

        if (is_file($reportsFile)) {
            $reports = CJSON::decode(file_get_contents($reportsFile));
        }

        return $reports;
    }

    /**
     * Check if current route is blacklisted (should not be processed)
     * @return bool
     */
    private function isRouteBlacklisted()
    {
        $result = false;
        $routes = $this->blacklistedRoutes;
        $requestRoute = Yii::app()->getUrlManager()->parseUrl(Yii::app()->getRequest());

        foreach ($routes as $route) {
            $route = str_replace('*', '([a-zA-Z0-9\/\-\._]{0,})', str_replace('/', '\/', '^' . $route));
            if (preg_match("/{$route}/", $requestRoute) !== 0) {
                $result = true;
                break;
            }
        }

        return $result;
    }

    /**
     * Save current report to history file and check size of the history.
     */
    private function saveReport()
    {
        $reports = $this->loadReports();
        $reports[] = $this->getReportInfo();

        if (count($reports) > $this->maxReportsCount) {
            array_shift($reports);
        }

        $reportsFile = "{$this->getReportSavePath()}/reports.json";
        file_put_contents($reportsFile, CJSON::encode($reports));
    }

    /**
     * Register asset files for overlay
     */
    private function initOverlay()
    {
        $assetPath = Yii::app()->assetManager->publish(__DIR__ . '/assets', false, -1, true);
        Yii::app()->clientScript->registerCssFile($assetPath . '/xhprof.css');
    }
}
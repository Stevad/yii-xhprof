<?php

/**
 * XHProf application component for Yii Framework 1.x.
 * Uses original XHProf UI to show reports. 
 * Developed to use with Yii2Debug extension (https://github.com/zhuravljov/yii2-debug)
 *
 * @author Vadym Stepanov <vadim.stepanov.ua@gmail.com>
 */
class XHProfComponent extends CApplicationComponent
{
    /**
     * Path to store last reports
     * @var string
     */
    public $reportPath;
    /**
     * How many reports store in history file
     * @var integer
     */
    public $maxReportsCount = 50;
    /**
     * Enable/disable component in Yii
     * @var boolean
     */
    public $enabled = true;
    /**
     * If set trigger xhprof to make profiling only if user set specified GET param with any value
     * @var string
     */
    public $triggerGetParam = 'xhprof';
    /**
     * Path alias to xhprof_lib directory (for proper work it must be inside webroot on the same level with xhprof_html)
     * @var string
     */
    public $xhprofLibPathAlias = 'webroot.xhprof_lib';
    /**
     * Path alias to xhprof_html directory (for proper work it must be inside webroot and to be accessible to view reports)
     * @var string
     */
    public $xhprofHtmlPath = 'webroot.xhprof_html';
    /**
     * Use or not flag XHPROF_FLAGS_NO_BUILTINS (http://php.net/manual/ru/xhprof.constants.php)
     * @var boolean
     */
    public $flagNoBuiltins = true;
    /**
     * Use or not flag XHPROF_FLAGS_CPU (http://php.net/manual/ru/xhprof.constants.php)
     * Default: false. Reason - some overhead in calculation on linux OS
     * @var boolean
     */
    public $flagCpu = false;
    /**
     * Use or not flag XHPROF_FLAGS_MEMORY (http://php.net/manual/ru/xhprof.constants.php)
     * @var boolean
     */
    public $flagMemory = true;
    /**
     * List of routes to not run xhprof on.
     * @var array
     */
    public $blacklistedRoutes = ['debug/*'];
    /**
     * Current run identifier
     * @var string
     */
    private $_runId;
    /**
     * Current report details
     * @var array
     */
    private $_reportInfo;
    /**
     * Flag to show if xhprof started or not.
     * @var boolean
     */
    private $_isActive = false;


    public function init()
    {
        parent::init();

        if (!$this->enabled) {
            return;
        }

        if ($this->triggerGetParam !== null && Yii::app()->request->getQuery($this->triggerGetParam) === null) {
            return;
        }

        if (!extension_loaded('xhprof')) {
            throw new CException('XHProf extension is not available');
        }

        Yii::setPathOfAlias('yii-xhprof', dirname(__FILE__));
        Yii::app()->setImport(array(
            'yii-xhprof.*'
        ));

        if ($this->reportPath === null) {
            $this->reportPath = Yii::app()->getRuntimePath() . '/xhprof';
        }

        $path = $this->reportPath;
        if (!is_dir($path)) {
            mkdir($path);
        }

        if ($this->isBlacklistedRoute()) {
            return;
        }

        Yii::app()->attachEventHandler('onBeginRequest', array($this, 'beginProfiling'));
        Yii::app()->attachEventHandler('onEndRequest', array($this, 'stopProfiling'));
    }

    /**
     * Get if xhprof profiler is currently active or not
     * @return boolean
     */
    public function isActive()
    {
        return $this->_isActive;
    }

    /**
     * Get unique run identifier for active profiler
     * @return string
     */
    public function getRunId()
    {
        if (!$this->isActive()) {
            return null;
        }
        if ($this->_runId === null) {
            $this->_runId = uniqid();
        }
        return $this->_runId;
    }

    /**
     * Get flags and start profiling
     */
    public function beginProfiling()
    {
        $flags = $this->getFlags();
        if ($flags !== 0) {
            xhprof_enable($flags);
        } else {
            xhprof_enable();
        }
        $this->_isActive = true;
    }

    /**
     * Stop profiling and save report
     */
    public function stopProfiling()
    {
        $runId = $this->getRunId();
        $runNamespace = Yii::app()->id;

        $xhprofData = xhprof_disable();
        $libPath = Yii::getPathOfAlias($this->xhprofLibPathAlias);
        include_once($libPath . '/utils/xhprof_lib.php');
        include_once($libPath . '/utils/xhprof_runs.php');

        $xhprof = new XHProfRuns_Default();
        $xhprof->save_run($xhprofData, $runNamespace, $runId);

        $this->saveReport();
    }

    /**
     * Get report details for current profiling process. Info consists of:
     * - unique run identifier (runId)
     * - namespace for run (ns, by default equal to current application ID)
     * - requested URL (url)
     * - time of request (time)
     * @return array key-valued list
     */
    public function getReportInfo()
    {
        if (!$this->isActive()) {
            return null;
        }
        if ($this->_reportInfo === null) {
            $request = Yii::app()->getRequest();
            $this->_reportInfo = array(
                'runId' => $this->getRunId(),
                'ns' => Yii::app()->id,
                'url' => $request->getHostInfo() . $request->getUrl(),
                'time' => time()
            );
        }
        return $this->_reportInfo;
    }

    /**
     * Get url's to view detailed report and callgraph in XHProf UI.
     * @param  array $report xhprof report by this component
     * @return array list with url's (keys: report, callgraph)
     */
    public function createReportUrls($report)
    {
        $baseUrl = $this->getHtmlUrl();
        return [
            'report' => $baseUrl . "/index.php?run={$report['runId']}&source={$report['ns']}",
            'callgraph' => $baseUrl . "/callgraph.php?run={$report['runId']}&source={$report['ns']}"
        ];
    }

    /**
     * Create information to see diff of current report and any other in history.
     * @param  array $currentReport report by this component
     * @return array key-valued list. Key is the run ID and value is the list with information:
     * - url to see diff report (compareUrl)
     * - user request url of history entry (url)
     * - time of the request (time)
     */
    public function createDiffData($currentReport)
    {
        $reports = $this->loadReports();
        $compareUrls = [];
        $baseUrl = $this->getHtmlUrl();

        $reports = array_reverse($reports);
        foreach ($reports as $report) {
            if ($currentReport['runId'] === $report['runId'] || $currentReport['ns'] !== $report['ns']) {
                continue;
            }
            $compareUrls[$report['runId']] = array(
                'compareUrl' => $baseUrl . "/index.php?run1={$currentReport['runId']}&run2={$report['runId']}&source={$currentReport['ns']}",
                'url' => $report['url'],
                'time' => $report['time']
            );
        }

        return $compareUrls;
    }

    /**
     * Check if current route is blacklisted
     * @return boolean
     */
    private function isBlacklistedRoute()
    {
        $result = false;
        if (count($this->blacklistedRoutes) === 0) {
            return $result;
        }

        $route = Yii::app()->getUrlManager()->parseUrl(Yii::app()->getRequest());
        foreach ($this->blacklistedRoutes as $r) {
            if ($r[strlen($r) - 1] === '*') {
                $r = substr($r, 0, -1);
                if (strpos($route, $r) === 0) {
                    $result = true;
                    break;
                }
            } elseif ($route === $r) {
                $result = true;
                break;
            }
        }

        return $result;
    }

    /**
     * Get URL to xhprof_html directory
     * @return string
     */
    private function getHtmlUrl()
    {
        $webRoot = Yii::getPathOfAlias('webroot');
        $htmlPath = Yii::getPathOfAlias($this->xhprofHtmlPath);

        if (($pos = strpos($htmlPath, $webRoot)) !== 0) {
            throw new CException('XHProf UI is not on the same server with site');
        }

        $request = Yii::app()->request;
        $baseUrl = substr($htmlPath, strlen($webRoot));
        $baseUrl = $request->getHostInfo() . $request->getBaseUrl() . $baseUrl;

        return $baseUrl;
    }

    /**
     * Calculate flags for xhprof_enable function
     * @return int
     */
    private function getFlags()
    {
        $flags = 0;
        if ($this->flagNoBuiltins) {
            $flags += XHPROF_FLAGS_NO_BUILTINS;
        }
        if ($this->flagCpu) {
            $flags += XHPROF_FLAGS_CPU;
        }
        if ($this->flagMemory) {
            $flags += XHPROF_FLAGS_MEMORY;
        }

        return $flags;
    }

    /**
     * Load list of previous reports from JSON file
     * @return array
     */
    private function loadReports()
    {
        $reportsFile = "{$this->reportPath}/reports.json";
        $reports = array();
        if (is_file($reportsFile)) {
            $reports = CJSON::decode(file_get_contents($reportsFile));
        }

        return $reports;
    }

    /**
     * Save current report to history file and check size of the history.
     */
    private function saveReport()
    {
        $reports = $this->loadReports();
        $reports[] = $this->getReportInfo();

        if (count($reports) > $this->maxReportsCount) {
            $deletedItem = array_shift($reports);
        }

        $reportsFile = "{$this->reportPath}/reports.json";
        file_put_contents($reportsFile, CJSON::encode($reports));
    }
}

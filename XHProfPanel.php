<?php

/**
 * Debug panel for Yii2Debug extension for fast access to XHProf results and list of previous runs with ability
 * to compare results between each others.
 *
 * @author Vadym Stepanov <vadim.stepanov.ua@gmail.com>
 */
class XHProfPanel extends Yii2DebugPanel
{
    public function getName()
    {
        return 'XHProf';
    }

    public function getDetail()
    {
        if (Yii::app()->getComponent('xhprof', false) === null) {
            return $this->render(__DIR__ . '/views/details_disabled_component.php');
        }

        $reports = Yii::app()->xhprof->loadReports();
        rsort($reports);

        $urlTemplates = array(
            'report' => Yii::app()->xhprof->getReportBaseUrl() . '/' . XHProf::$urlTemplates['report'],
            'callgraph' => Yii::app()->xhprof->getReportBaseUrl() . '/' . XHProf::$urlTemplates['callgraph'],
            'diff' => Yii::app()->xhprof->getReportBaseUrl() . '/' . XHProf::$urlTemplates['diff']
        );

        $js = <<<EOD
XHProf.urlReportTemplate = '{$urlTemplates['report']}';
XHProf.urlCallgraphTemplate = '{$urlTemplates['callgraph']}';
XHProf.urlDiffTemplate = '{$urlTemplates['diff']}';
EOD;

        $assetPath = Yii::app()->assetManager->publish(__DIR__ . '/assets', false, -1, true);
        /** @var CClientScript $cs */
        $cs = Yii::app()->clientScript;
        $cs->registerScriptFile($assetPath . '/xhprof.js', CClientScript::POS_HEAD);
        $cs->registerScript('xhprof_init', $js, CClientScript::POS_END);

        $urls = array();
        $data = $this->getData();

        if ($data['enabled']) {
            $urls['report'] = XHProf::getInstance()->getReportUrl($data['runId'], $data['ns']);
            $urls['callgraph'] = XHProf::getInstance()->getCallgraphUrl($data['runId'], $data['ns']);
        }

        return $this->render(__DIR__ . '/views/details.php', array(
            'enabled' => $data['enabled'],
            'run' => array(
                'id' => $data['runId'],
                'ns' => $data['ns']
            ),
            'urls' => $urls,
            'reports' => $reports
        ));
    }

    public function getSummary()
    {
        if (Yii::app()->getComponent('xhprof', false) === null) {
            return null;
        }

        XHProf::getInstance()->setHtmlUrlPath(Yii::app()->xhprof->getReportBaseUrl());

        $urls = array();
        $data = $this->getData();
        if ($data['enabled']) {
            $urls['report'] = XHProf::getInstance()->getReportUrl($data['runId'], $data['ns']);
            $urls['callgraph'] = XHProf::getInstance()->getCallgraphUrl($data['runId'], $data['ns']);
        }
        return $this->render(__DIR__ . '/views/panel.php', array(
            'enabled' => $data['enabled'],
            'urls' => $urls
        ));
    }

    public function save()
    {
        if (Yii::app()->getComponent('xhprof', false) === null) {
            return null;
        }

        return Yii::app()->xhprof->getReportInfo();
    }
}
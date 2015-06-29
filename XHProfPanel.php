<?php 

/**
 * Debug panel for Yii2Debug extension for fast access to XHProf report and callgraph.
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
        $data = $this->getData();
        $urls = Yii::app()->xhprof->createReportUrls($data);
        $diffData = Yii::app()->xhprof->createDiffData($data);
        return $this->render(dirname(__FILE__) . '/views/details.php', array(
            'urls' => $urls,
            'diffs' => $diffData
        ));
    }

    public function getSummary()
    {
        $urls = Yii::app()->xhprof->createReportUrls($this->getData());
        return $this->render(dirname(__FILE__) . '/views/panel.php', $urls);
    }

    public function save()
    {
        if (Yii::app()->getComponent('xhprof', false) !== null) {
            return Yii::app()->xhprof->getReportInfo();
        } else {
            return null;
        }
    }
}

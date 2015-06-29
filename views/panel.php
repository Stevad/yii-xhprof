<?php
/* @var XHProfPanel $this */
/* @var string $report */
/* @var string $callgraph */
?>
<div class="yii2-debug-toolbar-block">
	<a href="<?php echo $this->getUrl() ?>">XHProf</a>
	<a href="<?php echo $report ?>" target="_blank"><span class="label label-info">Report</span></a>
	<a href="<?php echo $callgraph ?>" target="_blank"><span class="label label-info">Callgraph</span></a>
</div>

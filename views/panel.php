<?php
/* @var XHProfPanel $this */
/* @var array $urls */
/* @var bool $enabled */
?>
<div class="yii2-debug-toolbar-block">
    <a href="<?php echo $this->getUrl() ?>">XHProf</a>
    <?php if ($enabled): ?>
        <a href="<?php echo $urls['report'] ?>" target="_blank"><span class="label label-info">Report</span></a>
        <a href="<?php echo $urls['callgraph'] ?>" target="_blank"><span class="label label-info">Callgraph</span></a>
    <?php else: ?>
        <span class="label">Not started</span>
    <?php endif; ?>
</div>
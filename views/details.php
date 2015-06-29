<?php
/* @var XHProfPanel $this */
/* @var string[] $urls */
/* @var string[] $diffs */
?>
<h3>Reports:</h3>
<ul>
	<li><a href="<?php echo $urls['report'] ?>" target="_blank">Detailed report</a></li>
	<li><a href="<?php echo $urls['callgraph'] ?>" target="_blank">Callgraph</a></li>
</ul>

<h4>Diff with one of the last runs:</h4>
<ul>
<?php if (count($diffs) > 0): ?>
	<?php foreach ($diffs as $runId => $info): ?>
	<li>[<?php echo date('Y-m-d H:i:s', $info['time']) ?>] <a href="<?php echo $info['compareUrl'] ?>" target="_blank">Run #<?php echo $runId ?></a> (<?php echo $info['url'] ?>)</li>
	<?php endforeach; ?>
<?php else: ?>
	<li>There is no any other runs yet.</li>
<?php endif; ?>
</ul>

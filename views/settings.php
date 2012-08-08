<?php if (!defined('APPLICATION')) die(); 

$IsDisabled = $this->Data('Configuration.IsDisabled');
?>

<h1><?php echo $this->Data('Title');?></h1>

<div class="FilterMenu">
<?php echo Anchor(T('Clean cache'), '/settings/pagespeed/cleancache', 'Button SmallButton');
if ($IsDisabled) echo Anchor(T('Turn ON'), '/settings/pagespeed/switch', 'Button SmallButton');
else  echo Anchor(T('Turn OFF'), '/settings/pagespeed/switch', 'Button SmallButton');
echo Anchor(T('Disable plugin'), '/settings/pagespeed/disable', 'Button SmallButton');
?>
</div>

<?php echo $this->Form->Open(); ?>
<?php echo $this->Form->Errors(); ?>
<ul>

<li>
	<?php 
	echo $this->Form->Label('Grouping', 'Plugins.PageSpeed.AllInOne');
	echo $this->Form->DropDown('Plugins.PageSpeed.AllInOne', $this->Data('GroupingItems'));
	?>
</li>

<li>
	<?php 
	echo $this->Form->Label('Defer JavaScript', 'Plugins.PageSpeed.DeferJavaScript');
	echo $this->Form->DropDown('Plugins.PageSpeed.DeferJavaScript', $this->Data('DeferJavaScriptItems'));
	?>
</li>

<li>
	<?php 
	echo $this->Form->Label('Enable Parallelize downloads', 'Plugins.PageSpeed.ParallelizeEnabled');
	echo $this->Form->CheckBox('Plugins.PageSpeed.ParallelizeEnabled');
	?>
</li>

<li>
	<?php 
	echo '<p>', T('Parallelize downloads across this hosts.'), '</p>';
	echo $this->Form->Label('Parallelize Hosts', 'Plugins.PageSpeed.ParallelizeHosts');
	echo $this->Form->TextBox('Plugins.PageSpeed.ParallelizeHosts');
	?>
</li>

<li>
	<?php 
	echo $this->Form->Label('CDN jQuery version', 'Plugins.PageSpeed.CDN.jquery');
	echo $this->Form->TextBox('Plugins.PageSpeed.CDN.jquery');
	?>
</li>

<li>
	<?php 
	echo $this->Form->Label('CDN jQueryUI version', 'Plugins.PageSpeed.CDN.jqueryui');
	echo $this->Form->TextBox('Plugins.PageSpeed.CDN.jqueryui');
	?>
</li>

<li>
	<?php 
	echo $this->Form->Label('CDN jQueryUI theme', 'Plugins.PageSpeed.CDN.jqueryui-theme');
	echo $this->Form->TextBox('Plugins.PageSpeed.CDN.jqueryui-theme');
	?>
</li>

<li>
	<?php 
	echo $this->Form->Label('Disable compressing css code', 'Plugins.PageSpeed.DisableMinifyCss');
	echo $this->Form->CheckBox('Plugins.PageSpeed.DisableMinifyCss');
	?>
</li>

<li>
	<?php 
	echo $this->Form->Label('Join Locale files', 'Plugins.PageSpeed.JoinLocaleSources');
	echo $this->Form->CheckBox('Plugins.PageSpeed.JoinLocaleSources');
	?>
</li>

</ul>

<?php echo $this->Form->Button('Save'); ?>
<?php echo $this->Form->Close(); ?>


<?php
/**
* refund payssion transaction
*/
function refund()
{
	function_requirements('has_acl');
	if ($GLOBALS['tf']->ima != 'admin' || !has_acl('client_billing')) {
		dialog('Not admin', 'Not Admin or you lack the permissions to view this page.');
		return false;
	}
	$smarty = new TFSmarty;
	add_output($smarty->fetch('payssion/refund.tpl'));
}
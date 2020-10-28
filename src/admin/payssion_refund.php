<?php

/**
* refund payssion transaction
* @throw 
*/
function payssion_refund()
{
	function_requirements('has_acl');
	$continue = true; $failed = false;
	if ($GLOBALS['tf']->ima != 'admin' || !has_acl('client_billing')) {
		$failed .= '<div>Not Admin or you lack the permissions to view this page.</div>';
		$continue = false;
	}
	if (!isset($GLOBALS['tf']->variables->request['transact_id']) || !$GLOBALS['tf']->variables->request['transact_id']) {
		$failed .= '<div>Transaction ID is not valid</div>';
		$continue = false;
	}
	if ($continue) {
		$db = get_module_db('default');
		$db->query("SELECT * FROM payssion WHERE transaction_id = '{$db->real_escape($GLOBALS['tf']->variables->request['transact_id'])}' AND state IN ('completed', 'paid_partial')");
		if ($db->num_rows() > 0) {
		} else {
			$failed = '<div>Transaction ID is invalid</div>';
			$continue = false;
		}
	}
	$smarty = new TFSmarty;
	if ($failed) {
		$smarty->assign('failed', $failed);
	}
	add_output($smarty->fetch('billing/payssion/refund.tpl'));
}
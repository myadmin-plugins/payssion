<?php

require_once __DIR__.'/../PayssionClient.php';

/**
*
* @return bool|void
* @throws \Exception
* @throws \SmartyException
*
* Refund payssion functionality
*
*/

function payssion_refund()
{
	function_requirements('has_acl');
	$continue = true; $failed = false; $updateInvoice = false; 
	$smarty = new TFSmarty;
	if ($GLOBALS['tf']->ima != 'admin' || !has_acl('client_billing')) {
		$failed .= '<div>Not Admin or you lack the permissions to view this page.</div>';
		$continue = false;
	}
	if (!isset($GLOBALS['tf']->variables->request['transaction']) || !$GLOBALS['tf']->variables->request['transaction']) {
		$failed .= '<div>Transaction ID is not valid</div>';
		$continue = false;
	}
	if ($continue) {
		$smarty->assign('transaction', $GLOBALS['tf']->variables->request['transaction']);
		$db = get_module_db('default');
		$dbIn = clone $db;
		$dbInP = clone $db;
		$dbU = clone $db;
		$db->query("SELECT * FROM payssion WHERE transaction_id = '{$db->real_escape($GLOBALS['tf']->variables->request['transaction'])}' AND state IN ('completed', 'paid_partial')");
		if ($db->num_rows() > 0) {
			$db->next_record(MYSQL_ASSOC);
			$payment_trans = $db->Record;
			$dbIn->query("SELECT * FROM invoices WHERE invoices_description = 'Payssion Payment {$payment_trans['transaction_id']}'");
			while ($dbIn->next_record(MYSQL_ASSOC)) {
				$dbInP->query("SELECT * FROM invoices WHERE invoices_description = 'REFUND: Payssion Payment {$payment_trans['transaction_id']}' AND invoices_extra = '{$dbIn->Record['invoices_id']}'");
				$pNumRows = $dbInP->num_rows();
				$serviceInfo = get_service($dbIn->Record['invoices_service'], $dbIn->Record['invoices_module']);
				$serviceSettings = get_module_settings($dbIn->Record['invoices_module']);
				$rows[] = [
					'invoice_id' => $dbIn->Record['invoices_extra'],
					'payment_id' => $dbIn->Record['invoices_id'],
					'amount' => $dbIn->Record['invoices_amount'],
					'currency' => $dbIn->Record['invoices_currency'],
					'service_id' => $serviceInfo[$serviceSettings['PREFIX'].'_id'],
					'service_field' => $serviceInfo[$serviceSettings['TITLE_FIELD']],
					'module' => $serviceSettings['TBLNAME'],
					'disable' => $pNumRows > 0 ? 'yes': 'no'
				];
				$pInvRows[$dbIn->Record['invoices_id']] = $dbIn->Record;
			}
			if (isset($GLOBALS['tf']->variables->request['submit'])) {
				if ($payment_trans['paid'] < $GLOBALS['tf']->variables->request['refund_amount']) {
					$failed .= '<div>You entered Refund amount is higher than paid amount!</div>';
				}
				if (isset($GLOBALS['tf']->variables->request['paymentInv']) && !empty($GLOBALS['tf']->variables->request['paymentInv'])) {
					myadmin_log('admin', 'info', 'Going with Payssion Refund', __LINE__, __FILE__);
					$requestVars = $GLOBALS['tf']->variables->request;
					$payssion = new PayssionClient(true);
					$response = null;
					try {
						$response = $payssion->refund([
								'amount' => $requestVars['refund_amount'],
								'currency' => $payment_trans['currency'],
								'transaction_id' => $payment_trans['transaction_id']
						]);
					} catch (Exception $e) {
						$msg = $e->getMessage();
						$failed .= '<div>Something went wrong! '.$msg.'</div>';
						myadmin_log('admin', 'info', 'Payssion Refund Error: '.$msg, __LINE__, __FILE__);
					}
					myadmin_log('admin', 'info', 'Payssion Refund Response: '.json_encode($response), __LINE__, __FILE__);
					if ($payssion->isSuccess()) {
						$success = "Payment refund is success! Refund Transaction: {$response['refund']['transaction_id']}";
						$dbU->query(make_insert_query('payssion', [
							'transaction_id' => $response['refund']['transaction_id'],
							'state' => $response['refund']['state'],
							'amount' => $response['refund']['amount'],
							'currency' => $response['refund']['currency'],
							'custid' => $payment_trans['custid'],
							'extra' => json_encode(['original_transaction_id' => $response['refund']['original_transaction_id']])
						]), __LINE__, __FILE__);
						$updateInvoice = true;
					} else {
						$failed .= '<div>Something went wrong! Unable to refund transaction!</div>';
					}
					if ($updateInvoice) {
						$amountRemaining = $GLOBALS['tf']->variables->request['refund_amount'];
						$totalInvoices = count($requestVars['paymentInv']);
						$count = 1;
						$invoice = new \MyAdmin\Orm\Invoice($db);
						foreach($requestVars['paymentInv'] as $index => $vars) {
							if ($totalInvoices == 1) {
								$amount = $requestVars['refund_amount'];
							} elseif ($count < $totalInvoices) {
								$amount = $pInvRows[$vars]['invoices_amount'];
							} elseif ($count == $totalInvoices) {
								$amount = $amountRemaining;
							}
							$count++;
							$amountRemaining = bcsub($amountRemaining, $amount);
							$now = mysql_now();
							$invoice->setDescription("REFUND: {$pInvRows[$vars]['invoices_description']}")
								->setAmount($amount)
								->setCustid($pInvRows[$vars]['invoices_custid'])
								->setType(2)
								->setDate($now)
								->setGroup(0)
								->setDueDate($now)
								->setExtra($pInvRows[$vars]['invoices_id'])
								->setService($pInvRows[$vars]['invoices_service'])
								->setPaid(0)
								->setCurrency($pInvRows[$vars]['invoices_currency'])
								->setModule($pInvRows[$vars]['invoices_module'])
								->save();
							if ($GLOBALS['tf']->variables->request['charge_inv'] == 'yes') {
								$dbU->query("UPDATE invoices SET invoices_paid = 0 WHERE invoices_id = {$pInvRows[$vars]['invoices_extra']}");
							}
							$dbU->query(make_insert_query('history_log', [
								'history_id' => null,
								'history_sid' => $GLOBALS['tf']->session->sessionid,
								'history_timestamp' => mysql_now(),
								'history_creator' => $GLOBALS['tf']->session->account_id,
								'history_owner' => $pInvRows[$vars]['invoices_custid'],
								'history_section' => 'payssion_refund',
								'history_type' => $transact_ID,
								'history_new_value' => "Refunded {$amount}",
								'history_old_value' => "Invoice Amount {$pInvRows[$vars]['invoices_amount']}"
							]), __LINE__, __FILE__);
						}
					}
				} else {
					$failed .= '<div>Nothing selected for refund!</div>';
				}
			}
			$smarty->assign('rows', $rows);
			$smarty->assign('totalAmount', $payment_trans['paid']);
		} else {
			$failed .= '<div>Transaction ID is invalid</div>';
			$continue = false;
		}
	}
	if ($failed) {
		$smarty->assign('failed', $failed);
	}
	if ($success) {
		$smarty->assign('success', $success);	
	}
	add_output($smarty->fetch('billing/payssion/refund.tpl'));
}
<?php
/**
 * NegativeDonations.Fix 
 *
 * Fix the negative donations by finding and cancelling the original ones instead
 *
 * @author B. Endres
 * @see https://civicoop.plan.io/issues/90
 */
function civicrm_api3_negative_donations_fix($params) {
  $counter = 0;
  $sql1 = "SELECT refund.id                      AS refund_id,
                  GROUP_CONCAT(original.id)      AS original_ids,
                  COUNT(original.id)             AS original_count,
                  civicrm_note.note              AS refund_note
                FROM civicrm_contribution refund
                LEFT JOIN civicrm_contribution original ON original.total_amount = -refund.total_amount 
                                                       AND original.contact_id = refund.contact_id
                                                       AND original.receive_date < refund.receive_date
                                                       AND DATEDIFF(refund.receive_date, original.receive_date) < 32
                LEFT JOIN civicrm_note ON civicrm_note.entity_table = 'civicrm_contribution' AND civicrm_note.entity_id = refund.id
                WHERE refund.total_amount < 0
                  AND refund.contact_id NOT IN (64599,63444,71866,35)
                  AND original.id IS NOT NULL
                GROUP BY refund.id;";

  $dao = CRM_Core_DAO::executeQuery($sql1);
  while ($dao->fetch()) {
    if ($dao->original_count == 1) {
      $params['refund_note'] = $dao->refund_note;
      $counter += civicrm_negative_donations_fix($dao->refund_id, $dao->original_ids, $params);
    }
  }

  return civicrm_api3_create_success($counter);
}


function civicrm_negative_donations_fix($refund_id, $original_ids, $params) {
  // load contributions
  error_log("MENDING {$original_ids}, deleting {$refund_id}");
  $refund = civicrm_api3('Contribution', 'getsingle', array('id' => $refund_id));
  $originals = civicrm_api3('Contribution', 'get', array('id' => array('IN' => explode($original_ids, ','))));

  // do some sanity checks
  if ($refund['contribution_status_id'] != 1) {
    return 0;
  }
  if ($originals['count'] < 1) {
    return 0; 
  }
  foreach ($originals['values'] as $original) {
    $original['contribution_status_id'] != 1;
    return 0;
  }


  // ready, let's go
  if (!empty($params['doit'])) {
    foreach ($originals['values'] as $original) {
      // calculate cancel reason
      $cancel_reason = "Negative contribution cleanup, deleted [{$refund['id']}]";
      if (!empty($params['refund_note'])) {
        $cancel_reason .= ": {$params['refund_note']}";
      }

      // mark all matched contributions as 'refunded'
      civicrm_api3('Contribution', 'create', array(
        'id'                     => $original['id'],
        'contribution_status_id' => '7',  // refunded
        'cancel_date'            => date('YmdHis', strtotime($refund['receive_date'])),
        'cancel_reason'          => $cancel_reason,
        'custom_75'              => ($refund['receive_date'] < "2016-05-01") ? 'BE69068068487178' : 'BE31890234567855',
        ));
    }

    // delete the negative one
    civicrm_api3('Contribution', 'delete', array('id' => $refund['id']));
  }
  return 1;
}

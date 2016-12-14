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
  $total_counter = $change_counter = 0;
  $matches = array();
  if (!empty($params['log_file'])) {
    $params['logger'] = fopen($params['log_file'], 'w');
  }

  // FIRST APPROACH: FIND THE ONE IDENTICAL CONTRIBUTION
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
      $total_counter += 1;
      $matches[$dao->refund_id] = $dao->original_ids;
      $params['refund_note'] = $dao->refund_note;
      $change_counter += civicrm_negative_donations_fix($dao->refund_id, $dao->original_ids, $params);
    }
  }


  // SECOND APPROACH: FIND MULTIPLE CONTRIBUTIONS IN A CERTAIN TIME
  $sql2 = "SELECT
              civicrm_note.note  AS refund_note,
              refund.id          AS refund_id,
              (SELECT GROUP_CONCAT(original.id)
                 FROM civicrm_contribution original
                 WHERE original.contact_id = refund.contact_id
                   AND original.receive_date < refund.receive_date
                   AND DATEDIFF(refund.receive_date, original.receive_date) < 25) AS original_ids
            FROM civicrm_contribution refund
            LEFT JOIN civicrm_note ON civicrm_note.entity_table = 'civicrm_contribution' AND civicrm_note.entity_id = refund.id
            WHERE refund.total_amount < 0
              AND refund.contact_id NOT IN (64599,63444,71866,35)
              AND -refund.total_amount = (SELECT SUM(original.total_amount)
                                           FROM civicrm_contribution original
                                           WHERE original.contact_id = refund.contact_id
                                             AND original.receive_date < refund.receive_date
                                             AND DATEDIFF(refund.receive_date, original.receive_date) < 25);";
  $dao = CRM_Core_DAO::executeQuery($sql2);
  while ($dao->fetch()) {
    if (!isset($matches[$dao->refund_id])) {
      $total_counter += 1;
      $matches[$dao->refund_id] = $dao->original_ids;
      $params['refund_note'] = $dao->refund_note;
      $change_counter += civicrm_negative_donations_fix($dao->refund_id, $dao->original_ids, $params);
    }
  }

  return civicrm_api3_create_success(array('total' => $total_counter, 'changed' => $change_counter));
}


function civicrm_negative_donations_fix($refund_id, $original_ids, &$params) {
  // load contributions
  $refund = civicrm_api3('Contribution', 'getsingle', array('id' => $refund_id));
  $originals = civicrm_api3('Contribution', 'get', array('id' => array('IN' => explode(',', $original_ids))));

  // do some sanity checks
  if ($refund['contribution_status_id'] != 1) {
    if (!empty($params['logger'])) {
      fputs($params['logger'], "\n\nContribution [{$refund['id']}] has status {$refund['contribution_status_id']}. Ignored\n");
      fflush($params['logger']);
    }
    return 0;
  }
  if ($originals['count'] < 1) {
    if (!empty($params['logger'])) {
      fputs($params['logger'], "\n\nNone of the contributions ({$original_ids}) for refund [{$refund['id']}] were found. Ignored\n");
      fflush($params['logger']);
    }
    return 0; 
  }
  foreach ($originals['values'] as $original) {
    if ($original['contribution_status_id'] != 1) {
      if (!empty($params['logger'])) {
        fputs($params['logger'], "\n\nOrigianl Contributions [{$original['contribution_status_id']}] for refund [{$refund['id']}] has status {$original['contribution_status_id']}. Ignored\n");
        fflush($params['logger']);
      }
      return 0;
    }
  }

  // ready, let's go:

  // calculate cancel reason
  $cancel_reason = "Negative contribution cleanup, deleted [{$refund['id']}]";
  if (!empty($params['refund_note'])) {
    $cancel_reason .= ": {$params['refund_note']}";
  }
  // calculate refund account
  $refund_account = ($refund['receive_date'] < "2016-05-01") ? 'BE69068068487178' : 'BE31890234567855';

  if (!empty($params['logger'])) {
    fputs($params['logger'], "\n\nFix and refund contribution [{$refund['id']}]:\n");
    fputs($params['logger'], "Message is: \"{$cancel_reason}\"\n");
    fputs($params['logger'], "Refund date is: {$refund['receive_date']}\n");
    fputs($params['logger'], "Refund account is: {$refund_account}\n");

    foreach ($originals['values'] as $original) {
      fputs($params['logger'], "Will set contribution {$original['id']} to 'Refunded'\n");
    }
    fflush($params['logger']);
  }

  if (!empty($params['doit'])) {
    foreach ($originals['values'] as $original) {
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

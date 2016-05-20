<?php
/**
 * SiActSource.Fix API Used to change the activity source for double recruiters
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_si_act_source_Fix($params) {
  $returnValues = array("Recruiters deduped and activity source contact fixed: ");
  $query = 'SELECT DISTINCT(sort_name) FROM civicrm_contact WHERE contact_sub_type LIKE %1';
  $dao = CRM_Core_DAO::executeQuery($query, array(1 => array('%recruiter%', 'String')));
  while ($dao->fetch()) {
    _civicrm_api3_process_recruiter($dao->sort_name);
    $returnValues[] = $dao->sort_name;
  }
  return civicrm_api3_create_success($returnValues, $params, 'SiActSource', 'Fix');
}

/**
 * Function to keep only the latest recruiter and update all activities to that kept contact for all dupes, then trash dupes
 * @param $sortName
 */
function _civicrm_api3_process_recruiter($sortName) {
  $savedContactId = NULL;
  $query = 'SELECT id FROM civicrm_contact WHERE sort_name = %1 AND contact_sub_type LIKE %2 ORDER BY id DESC';
  $params = array(
    1 => array((string) $sortName, 'String'),
    2 => array('%recruiter%', 'String')
  );
  $dao = CRM_Core_DAO::executeQuery($query, $params);
  while ($dao->fetch()) {
    if (!$savedContactId) {
      // first record is highest id, save that as it is the contact that will be kept
      $savedContactId = $dao->id;
    } else {

      // find all activity contacts for contact and set them to saved contact
      $update = "UPDATE civicrm_activity_contact SET contact_id = %1 WHERE contact_id = %2";
      $updateParams = array(
        1 => array($savedContactId, 'Integer'),
        2 => array($dao->id, 'Integer')
      );
      CRM_Core_DAO::executeQuery($update, $updateParams);
      // then trash contact
      CRM_Core_DAO::executeQuery('UPDATE civicrm_contact SET is_deleted = %1 WHERE id = %2', array(
        1 => array(1, 'Integer'),
        2 => array($dao->id, 'Integer')
      ));
    }
  }
}


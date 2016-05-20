<?php
/**
 * RecruitRelation.Fix API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_recruit_relation_Fix($params) {
  $countFixes = NULL;
  $directResultOrgId = civicrm_api3('Contact', 'Getvalue', array(
    'organization_name' => "DirectResult",
    'contact_type' => "Organization",
    'return' => "id"
  ));
  $recruiters = array();
  $recruitRelTypeId = civicrm_api3('RelationshipType', 'Getvalue', array('name_a_b' => "recruiter_is", 'return' => 'id'));
  $query = "SELECT cc.id as contact_id, cc.first_name, cc.last_name, info.external_recruiter_id, cc.created_date as contact_created_date
    FROM civicrm_contact cc LEFT JOIN civicrm_relationship rel ON cc.id = rel.contact_id_b AND relationship_type_id = %1
    LEFT JOIN civicrm_value_recruiter_info info ON cc.id = info.entity_id 
    WHERE cc.contact_sub_type LIKE %2";
  $queryParams = array(
    1 => array($recruitRelTypeId, 'Integer'),
    2 => array("%recruiter%", 'String'));
  $dao = CRM_Core_DAO::executeQuery($query, $queryParams);
  while ($dao->fetch()) {
    // if DirectResult name then set relationship for DirectResult
    if ($dao->last_name == 'DirectResult') {
      $csvData['recruiting_organization_id'] = $directResultOrgId;
      $csvData['start_date'] = $dao->contact_created_date;
      _civicrm_api3_create_relationship($csvData, $dao->contact_id , $recruitRelTypeId);
    } else {
      $csvData = _civicrm_api3_get_csv_data($dao);
      if (!empty($csvData)) {
        // if no recruiter_id yet, set recruiter_id
        if (empty($dao->external_recruiter_id)) {
          _civicrm_api3_update_recruiter_id($csvData['recruiter_id'], $dao->contact_id);
        }
        _civicrm_api3_create_relationship($csvData, $dao->contact_id, $recruitRelTypeId);
        $countFixes++;
        $recruiters[] = $dao->first_name . " " . $dao->last_name;
      }
    }
  }
  return civicrm_api3_create_success(array($countFixes.' recruiters fixed:'.implode(';', $recruiters)), $params, 'RecruitRelation', 'Fix');
}

/**
 * Function to create relationship between recruiter and recruiting organization if not there
 *
 * @param $csvData
 * @param $contactId
 * @param $recruitRelTypeId
 */
function _civicrm_api3_create_relationship($csvData, $contactId, $recruitRelTypeId) {
  $params = array(
    'relationship_type_id' => $recruitRelTypeId,
    'contact_id_a' => $csvData['recruiting_organization_id'],
    'contact_id_b' => $contactId
  );
  $countCurrent = civicrm_api3('Relationship', 'Getcount', $params);
  switch ($countCurrent) {
    // create new if not present
    case 0:
      $params['start_date'] = $csvData['start_date'];
      $params['is_active'] = 1;
      try {
        civicrm_api3('Relationship', 'Create', $params);
      } catch (CiviCRM_API3_Exception $ex) {}
      break;
    // check if active and if not, make active
    case 1:
      $current = civicrm_api3('Relationship', 'Getsingle', $params);
      if ($current['is_active'] == 0) {
        $params['is_active'] = 1;
        $params['id'] = $current['id'];
        try {
          civicrm_api3('Relationship', 'Create', $params);
        } catch (CiviCRM_API3_Exception $ex) {}
      }
      break;
  }
}

/**
 * Function to update or create the recruiter id custom field for the contact
 *
 * @param $recruiterId
 * @param $contactId
 */
function _civicrm_api3_update_recruiter_id($recruiterId, $contactId) {
  $params = array(
    1 => array($recruiterId, 'String'),
    2 => array($contactId, 'Integer'));
  $countQuery = "SELECT COUNT(*) FROM civicrm_value_recruiter_info WHERE entity_id = %1";
  $count = CRM_Core_DAO::singleValueQuery($countQuery, array(1 => array($contactId, 'Integer')));
  if ($count > 0) {
    $query = "UPDATE civicrm_value_recruiter_info SET external_recruiter_id = %1 WHERE entity_id = %2";
  } else {
    $query = "INSERT INTO civicrm_value_recruiter_info (external_recruiter_id, entity_id) VALUES(%1, %2)";
  }
  CRM_Core_DAO::executeQuery($query, $params);
}

/**
 * Function to get the recruiter data saved from the csv files
 * 
 * @param object $recruiter
 * @return array
 */
function _civicrm_api3_get_csv_data($recruiter) {
  $result = array();
  $query = 'SELECT * FROM recruit_data WHERE recruiter_first_name = %1 AND recruiter_last_name = %2';
  $params = array(
    1 => array($recruiter->first_name, 'String'),
    2 => array($recruiter->last_name, 'String'));
  $dao = CRM_Core_DAO::executeQuery($query, $params);
  if ($dao->fetch()) {
    $result['recruiter_id'] = $dao->recruiter;
    $result['recruiting_organization_id'] = $dao->recruiting_organization_id;
    $result['start_date'] = $recruiter->contact_created_date;
  }
  return $result;
}


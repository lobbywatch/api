<?php
declare(strict_types=1);

namespace App\Routes;

use Exception;
use SoapClient;
use SoapFault;
use function App\domain\ApiResponse\api_response;
use function App\domain\ApiResponse\forbidden_response;
use function App\Lib\Http\add_exception;
use function App\Lib\Http\callAPI;
use function App\Lib\Http\json_response;
use function App\Lib\String\clean_str;
use function App\Store\db_query;

/**
 * Flag to indicate a query call should simply return NULL.
 *
 * This is used for queries that have no reasonable return value anyway, such
 * as INSERT statements to a table without a serial primary key.
 */
define('LW_DB_RETURN_NULL', 0);

/**
 * Flag to indicate a query call should return the prepared statement.
 */
define('LW_DB_RETURN_STATEMENT', 1);

/**
 * Flag to indicate a query call should return the number of affected rows.
 */
define('LW_DB_RETURN_AFFECTED', 2);

/**
 * Flag to indicate a query call should return the "last insert id".
 */
define('LW_DB_RETURN_INSERT_ID', 3);

function route_ws_uid($table, $uid) {
  global $show_sql, $show_stacktrace;
  global $no_cors;
  global $zefix_ws_login;
  global $allowed_uid_access_keys;
  $success = true;
  $count = 0;
  $items = null;
  $message = '';
  $sql = '';

  // Protect Zefix WS, either with key or from cyon.ch server or localhost
  if (in_array($table, ['uid', 'zefix-rest', 'uid-bfs']) && (empty($_GET['access_key']) || !in_array($_GET['access_key'], $allowed_uid_access_keys, true)) && $_SERVER['REMOTE_ADDR'] !== '91.206.24.232' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    json_response(forbidden_response());
  } else if (in_array($table, ['zefix-soap']) && (empty($_GET['access_key']) || !in_array($_GET['access_key'], $zefix_ws_login['keys'], true)) && $_SERVER['REMOTE_ADDR'] !== '91.206.24.232' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    json_response(forbidden_response());
  }

  try {
    if ($table == 'uid-bfs') {
      $items = _lobbywatch_fetch_ws_uid_bfs_data($uid, 0, false, false);
      $no_cors = true; // Disable CORS since it is a protected service
    } else if ($table == 'zefix-soap') {
      $items = _lobbywatch_fetch_ws_zefix_soap_data($uid, 0, false, false);
      $no_cors = true; // Disable CORS since it is a protected service
    } else if ($table == 'zefix-rest') {
      $items = _lobbywatch_fetch_ws_zefix_rest_data($uid, 0, false);
      $no_cors = true; // Disable CORS since it is a protected service
    } else if ($table == 'uid') {
      $items = _lobbywatch_fetch_ws_zefix_rest_data($uid, 0, false);
      if (!$items['success']) {
        $message .= "zefix-rest unsuccessful ({$items['message']}), calling uid@bfs | ";
        $items = _lobbywatch_fetch_ws_uid_bfs_data($uid, 0, false, false, 0);
      }
      $no_cors = true; // Disable CORS since it is a protected service
    } else {
      // Must not happen
      $items = null;
    }

    $count = $items['count']; // already set by fillDataFromUIDResult()
    $success = $items['success'];
    $message .= $items['message'];
    $sql .= $items['sql'];
  } catch (Exception $e) {
    $message .= add_exception($e, $show_stacktrace);
    $success = false;
  } finally {
    $response = api_response($success, $count, $message, $show_sql ? $sql : '', $table, $success ? $items['data'] : null);
    json_response($response, cors: !$no_cors);
  }
}

function _lobbywatch_fetch_ws_uid_bfs_data($uid_raw, $verbose = 0, $ssl = true, $test_mode = false, $num_retries = 0) {
  $data = initDataArray();

  if (!_lobbywatch_check_uid_format($uid_raw, $uid, $data['message'])) {
    $data['data'] = [];
    $data['success'] = false;
    return $data;
  }

  $data['sql'] .= "uid=$uid";

  if (!_lobbywatch_check_uid_check_digit($uid, $data['message'])) {
    $data['data'] = [];
    $data['success'] = false;
    return $data;
  }

  $client = initSoapClient($data, getUidBfsWsLogin($test_mode), $verbose, $ssl);

  /*
  Parameter: uid
  Datentyp: eCH-0097:uidStructureType
      http://www.ech.ch/vechweb/page?p=dossier&documentNumber=eCH-0097&documentVersion=2.0
      http://www.ech.ch/alfresco/guestDownload/attach/workspace/SpacesStore/978ac878-a051-401d-b219-f6e540cadab5/STAN_d_REP_2015-11-26_eCH-0097_V2.0_Datenstandard%20Unternehmensidentifikation.pdf
  Beschreibung: UID des gesuchten Unternehmens

  Rückgabewert: eCH-0108:organisationType Array
      http://www.ech.ch/vechweb/page?p=dossier&documentNumber=eCH-0108&documentVersion=3.0
      http://www.ech.ch/alfresco/guestDownload/attach/workspace/SpacesStore/bc371174-261e-4152-9d60-3b5a4e79ce7b/STAN_d_DEF_2014-04-11_eCH-0108_V3.0_Unternehmens-Identifikationsregister.pdf

  Mögliche Fehlermeldungen:
  - Data_validation_failed
  - Request_limit_exceeded

  <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:uid="http://www.uid.admin.ch/xmlns/uid-wse" xmlns:ns="http://www.ech.ch/xmlns/eCH-0097-f/2">
   <soapenv:Header/>
   <soapenv:Body>
      <uid:GetByUID>
         <!--Optional:-->
         <uid:uid>
            <!--Optional:-->
            <ns:uidOrganisationIdCategorie>CHE</ns:uidOrganisationIdCategorie>
            <!--Optional:-->
            <ns:uidOrganisationId>107810911</ns:uidOrganisationId>
         </uid:uid>
      </uid:GetByUID>
   </soapenv:Body>
  </soapenv:Envelope>
  */

  ws_get_organization_from_uid_bfs($uid, $client, $data, $verbose, $num_retries);
  return $data;
}

function _lobbywatch_fetch_ws_zefix_soap_data($uid_raw, $verbose = 0, $ssl = true, $test_mode = false) {
  $data = initDataArray();

  if (!_lobbywatch_check_uid_format($uid_raw, $uid, $data['message'])) {
    $data['data'] = [];
    $data['success'] = false;
    return $data;
  }

  $data['sql'] .= "uid=$uid";

  if (!_lobbywatch_check_uid_check_digit($uid, $data['message'])) {
    $data['data'] = [];
    $data['success'] = false;
    return $data;
  }

  $client = initSoapClient($data, getZefixSoapWsLogin($test_mode), $verbose, $ssl);

  ws_get_organization_from_zefix_soap($uid, $client, $data, $verbose);
  return $data;
}

function _lobbywatch_fetch_ws_zefix_rest_data($uid_raw, $verbose = 0, $test_mode = false) {
  $data = initDataArray();

  if (!_lobbywatch_check_uid_format($uid_raw, $uid, $data['message'])) {
    $data['data'] = [];
    $data['success'] = false;
    return $data;
  }

  $data['sql'] .= "uid=$uid";

  if (!_lobbywatch_check_uid_check_digit($uid, $data['message'])) {
    $data['data'] = [];
    $data['success'] = false;
    return $data;
  }

  ws_get_organization_from_zefix_rest($uid, $data, $verbose, $test_mode);
  return $data;
}

function ws_get_organization_from_zefix_rest($uid_raw, &$data, $verbose, $test_mode = false) {
  global $zefix_ws_login;

  $response = null;
  try {
    $uid = getUIDnumber($uid_raw);
    /* Set your parameters for the request */
    $params = array(
      'uid' => $uid,
    );
    // OpenAPI: https://www.zefix.admin.ch/ZefixPublicREST/
    $base_url = $test_mode ? 'https://www.zefixintg.admin.ch/ZefixPublicREST/api/v1/company/uid' : 'https://www.zefix.admin.ch/ZefixPublicREST/api/v1/company/uid';
    $url = "$base_url/CHE$uid";
    if ($verbose > 8) print("URL: $url\n");
    $basicAuthUsernamePassword = "{$zefix_ws_login['username']}:{$zefix_ws_login['password']}";
    $response_raw = callAPI('GET', $url, false, $basicAuthUsernamePassword);
    $response_json = json_decode($response_raw, false, 512, JSON_THROW_ON_ERROR);
    if (isset($response_json)) {
      fillDataFromZefixRestResult($response_json, $data);
    } else {
      $data['message'] .= 'No Result from zefix webservice. ';
      $data['success'] = false;
      $data['sql'] = "uid=$uid";
    }
  } catch (Exception $e) {
    // $data['message'] .= _utils_get_exception($e);
    $data['message'] .= $e->GetMessage();
    $data['success'] = false;
    $data['sql'] = "uid=$uid";
  } finally {
    ws_verbose_logging(null, $response, $data, $verbose);
  }
  return $response;
}

// OpenAPI: https://www.zefix.admin.ch/ZefixPublicREST/
function fillDataFromZefixRestResult($json, &$data) {
  if (!empty((array)$json)) {
//       print_r($object);
    if (is_array($json)) {
      $ot = $json[0];
      $data['count'] = count($json);
    } else {
      $ot = $json;
      $data['count'] = 1;
    }
    $oid = $ot;
    $uid_ws = $oid->uid;
    /*
    ACTIVE, CANCELLED, BEING_CANCELLED
    */
    $status = $ot->status;
    if (!empty($ot->address)) {
      $base_address = $ot->address;
      // $address = $base_address[0] ?? $base_address;
      // $address2 = $base_address[1] ?? null;
      $address = is_array($base_address) ? $base_address[0] : $base_address;
      $address2 = is_array($base_address) && !empty($base_address[1]) ? $base_address[1] : null;
    } else {
      $base_address = $address = $address2 = null;
    }
    $old_hr_id = !empty($oid->chid) ? $oid->chid : null;
    $legal_form_handelsregister_uid = $oid->legalForm->uid ?? null;
    $data['data'] = array(
      'uid' => formatUID($uid_ws),
      'uid_raw' => $uid_ws,
      'alte_hr_id' => $old_hr_id ?? null,
      'ch_id' => $old_hr_id ?? null,
      'ehra_id' => $oid->ehraid ?? null,
      'name' => clean_str($oid->name),
      'name_de' => clean_str($oid->name),
      'abkuerzung_de' => extractAbkuerzung($oid->name),
      // TODO 'name_fr' => $ot->organisation->organisationIdentification->organisationName, TODO
      'rechtsform_handelsregister' => $legal_form_handelsregister_uid,
      'rechtsform' => _lobbywatch_ws_get_rechtsform($legal_form_handelsregister_uid),
      'rechtsform_zefix' => $oid->legalForm->id ?? null,
      'adresse_strasse' => !empty($address->street) ? (clean_str($address->street) . (!empty($address->houseNumber) ? ' ' . clean_str($address->houseNumber) : '')) : null,
      // 'adresse_zusatz' => (!empty($address->addon) ? $address->addon : null) ?? ('Postfach ' . $address->poBox) ?? ('Postfach ' . $address2->poBox) ?? null,
      'adresse_zusatz' => !empty($address->addon) ? clean_str($address->addon) : (!empty($address->poBox) ? 'Postfach ' . clean_str($address->poBox) : (!empty($address2->poBox) ? 'Postfach ' . clean_str($address2->poBox) : null)),
      'ort' => $address->city ? clean_str($address->city) : null,
      'adresse_plz' => !empty($address->swissZipCode) && is_numeric($address->swissZipCode) ? +$address->swissZipCode : null,
      'bfs_gemeinde_nr' => !empty($ot->legalSeatId) && is_numeric($ot->legalSeatId) ? +$ot->legalSeatId : null,
      'land_iso2' => 'CH' ?? null,
      'land_id' => _lobbywatch_ws_get_land_id('CH') ?? null,
      'handelsregister_url' => $ot->cantonalExcerptWeb ? trim($ot->cantonalExcerptWeb) : null,
      'handelsregister_ws_url' => $ot->wsLink ?? null, // TODO what for?
      'zweck' => $ot->purpose ? "Zweck: " . clean_str($ot->purpose) : null,
      'register_kanton' => $ot->canton ?? null,
      'inaktiv' => $status != 'ACTIVE',
      'nominalkapital' => $ot->capitalNominal,
      'in_handelsregister' => true,
    );
    return $data['data'];
  } else {
    $data['message'] .= 'Nothing found';
    $data['success'] = false;
    return false;
  }
}

function getZefixSoapWsLogin($test_mode = false) {
  global $zefix_ws_login;
  $username = $zefix_ws_login['username'];
  $password = $zefix_ws_login['password'];

//   print_r($zefix_ws_login);

  if ($test_mode) {
//     $wsdl = "http://" . urlencode($username) . ':' . urlencode($password) . "@test-e-service.fenceit.ch/ws-zefix-1.6/ZefixService?wsdl";
//     $wsdl = "https://www.e-service.admin.ch/wiki/download/attachments/44827026/ZefixService.wsdl?version=2&modificationDate=1428391225000";
    // Workaround PHP bug https://bugs.php.net/bug.php?id=61463
    $wsdl = "https://cms.lobbywatch.ch/sites/lobbywatch.ch/app/common/ZefixService16Test.wsdl";
//     $host = 'test-e-service.fenceit.ch';
    $host = 'cms.lobbywatch.ch';
  } else {
//     $wsdl = "http://" . urlencode($username) . ':' . urlencode($password) . "@www.e-service.admin.ch/ws-zefix-1.6/ZefixService?wsdl";
//     $wsdl = "https://www.e-service.admin.ch/wiki/download/attachments/44827026/ZefixService.wsdl?version=2&modificationDate=1428391225000";
    // Workaround PHP bug https://bugs.php.net/bug.php?id=61463
    $wsdl = "https://cms.lobbywatch.ch/sites/lobbywatch.ch/app/common/ZefixService16.wsdl";
//     $host = 'www.e-service.admin.ch';
    $host = 'cms.lobbywatch.ch';
  }
  $response = array(
    'wsdl' => $wsdl,
    'username' => $username,
    'password' => $password,
    'host' => $host,
  );
  return $response;
}

function ws_get_organization_from_zefix_soap($uid_raw, $client, &$data, $verbose) {
  /* Invoke webservice method with your parameters. */
  $response = null;
  try {
    $uid = getUIDnumber($uid_raw);
    /* Set your parameters for the request */
    $params = array(
      'uid' => $uid,
    );
    $response = $client->GetByUidFull($params);
    if (isset($response->result)) {
      fillDataFromZefixSoapResult($response->result, $data);
    } else {
      $data['message'] .= 'No Result from zefix webservice. ';
      $data['success'] = false;
      $data['sql'] = "uid=$uid";
    }
  } catch (Exception $e) {
    // $data['message'] .= _utils_get_exception($e);
    $data['message'] .= $e->GetMessage();
    $data['success'] = false;
    $data['sql'] = "uid=$uid";
  } finally {
    ws_verbose_logging($client, $response, $data, $verbose);
  }
  return $response;
}

/**
 * @deprecated
 */
function fillDataFromZefixSoapResult($object, &$data) {
  if (!empty((array)$object)) {
    if (is_array($object->companyInfo)) {
      $ot = $object->companyInfo[0];
      $data['count'] = count($object->companyInfo);
    } else {
      $ot = $object->companyInfo;
      $data['count'] = 1;
    }
    $oid = $ot;
    $uid_ws = $oid->uid;
    if (!empty($ot->address)) {
      $base_address = $ot->address;
      $address = is_array($base_address) ? $base_address[0]->addressInformation : $base_address->addressInformation;
      $address2 = is_array($base_address) && !empty($base_address[1]->addressInformation) ? $base_address[1]->addressInformation : null;
    } else {
      $base_address = $address = $address2 = null;
    }
    $old_hr_id = !empty($oid->chid) ? $oid->chid : null;
    $legel_form_handelsregister = !empty($oid->legalform->legalFormUid) ? $oid->legalform->legalFormUid : null;
    $data['data'] = array(
      'uid' => formatUID($uid_ws),
      'uid_raw' => $uid_ws,
      'alte_hr_id' => !empty($old_hr_id) ? $old_hr_id : null,
      'name' => $oid->name,
      'name_de' => $oid->name,
      // TODO 'name_fr' => $ot->organisation->organisationIdentification->organisationName, TODO
      'rechtsform_handelsregister' => $legel_form_handelsregister,
      'rechtsform' => _lobbywatch_ws_get_rechtsform($legel_form_handelsregister),
      'rechtsform_zefix' => !empty($oid->legalform->legalFormId) ? $oid->legalform->legalFormId : null,
      'adresse_strasse' => !empty($address->street) ? ($address->street . (!empty($address->houseNumber) ? ' ' . $address->houseNumber : '')) : null,
      'adresse_zusatz' => !empty($address->addressLine1) ? $address->addressLine1 : (!empty($address2->postOfficeBoxNumber) ? 'Postfach ' . $address2->postOfficeBoxNumber : null),
      'ort' => !empty($address->town) ? $address->town : null,
      'adresse_plz' => !empty($address->swissZipCode) ? $address->swissZipCode : null,
      'land_iso2' => !empty($address->country) ? $address->country : null,
      'land_id' => !empty($address->country) ? _lobbywatch_ws_get_land_id($address->country) : null,
      'handelsregister_url' => !empty($ot->webLink) ? $ot->webLink : null,
      'handelsregister_ws_url' => !empty($ot->wsLink) ? $ot->wsLink : null,
      'zweck' => !empty($ot->purpose) ? "Zweck: " . trim($ot->purpose) : null,
      'register_kanton' => getCantonCodeFromZefixRegistryId($ot->registerOfficeId),
    );
  } else {
    $data['message'] .= 'Nothing found';
    $data['success'] = false;
  }
}

function getCantonCodeFromZefixRegistryId($id) {
  $canton = null;
  switch ($id) {
    case 20:
      return 'ZH';
    case 36:
      return 'BE';
    case 100:
      return 'LU';
    case 120:
      return 'UR';
    case 130:
      return 'SZ';
    case 140:
      return 'OW';
    case 150:
      return 'NW';
    case 160:
      return 'GL';
    case 170:
      return 'ZG';
    case 217:
      return 'FR';
    case 241:
      return 'SO';
    case 270:
      return 'BS';
    case 280:
      return 'BL';
    case 290:
      return 'SH';
    case 200:
      return 'AR';
    case 310:
      return 'AI';
    case 320:
      return 'SG';
    case 350:
      return 'GR';
    case 400:
      return 'AG';
    case 440:
      return 'TG';
    case 501:
      return 'TI';
    case 550:
      return 'VD';
    case 600:
      return 'VS';
    case 621:
      return 'VS';
    case 626:
      return 'VS';
    case 645:
      return 'NE';
    case 660:
      return 'GE';
    case 670:
      return 'JU';
    default:
      return null;
  }
}

function initDataArray() {
  $data = [];
  $data['message'] = '';
  $data['sql'] = '';
  $data['data'] = [];
  $data['success'] = true;
  $data['count'] = 0;
  return $data;
}

function initSoapClient(&$data, $login, $verbose = 0, $ssl = true) {
  if ($ssl) {
    $ssl_config = array(
      "verify_peer" => true,
      "allow_self_signed" => false,
      "cafile" => dirname(__FILE__) . "/../settings/cacert.pem",
      "verify_depth" => 5,
      "peer_name" => $login['host'],
      'disable_compression' => true,
      'SNI_enabled' => true,
      'ciphers' => 'ALL!EXPORT!EXPORT40!EXPORT56!aNULL!LOW!RC4',
    );
  } else {
    $ssl_config = array(
      'verify_peer' => false,
      'verify_peer_name' => false,
      'allow_self_signed' => true,
    );
  }

  $context = stream_context_create(array(
    'ssl' => $ssl_config,
    'http' => array(
      'user_agent' => 'PHPSoapClient',
    ),
  ));

//   $wsdl = "https://www.uid-wse.admin.ch/V3.0/PublicServices.svc?wsdl";
  $soapParam = array(
    "stream_context" => $context,
    "trace" => true,
    "exceptions" => true,
  );

  if (isset($login['username']) && isset($login['password'])) {
    $soapParam['login'] = $login['username'];
    $soapParam['password'] = $login['password'];
  }

  $wsdl = $login['wsdl'];

  $data['sql'] .= " | wsdl=$wsdl";

  /* Initialize webservice with your WSDL */
  $client = new SoapClient($wsdl, $soapParam);
  return $client;
}

/** Retries sleep time 2**($i + 3). $i = 9 -> totally 2.3h sleep ((2**13 - 1)  / 3600) */
function ws_get_organization_from_uid_bfs($uid_raw, $client, &$data, $verbose, $num_retries = 0, &$retry_log = '') {
  /* Invoke webservice method with your parameters. */
  $response = null;
  try {
    $uid = getUIDnumber($uid_raw);
    /* Set your parameters for the request */
    $params = array(
      'uid' => array(
        'uidOrganisationIdCategorie' => 'CHE',
        'uidOrganisationId' => $uid,
      ),
    );
    for ($i = 0; !$response; $i++) {
      try {
        $response = $client->GetByUID($params);
      } catch (SoapFault $e) {
        if ($e->faultstring == 'Request_limit_exceeded') {
          if ($i < $num_retries) {
            if ($verbose > 8) print("→" . 2 ** ($i + 3) . "s…\n");
            $retry_log .= '.';
            sleep(2 ** ($i + 3));
          } else {
            $fault = (array)$e->detail->BusinessFault;
            throw new Exception("{$fault['Error']} [op={$fault['Operation']}]: {$fault['ErrorDetail']}", $e->getCode(), $e);
          }
        } else {
          throw $e;
        }
      }
    }

    if (isset($response->GetByUIDResult)) {
      fillDataFromUidBfsResult50($response->GetByUIDResult, $data);
    } else {
      $data['message'] .= 'No Result from uid webservice.';
      $data['success'] = false;
      $data['sql'] = "uid=$uid";
    }
  } catch (Exception $e) {
    $data['message'] .= $e->GetMessage();
    $data['success'] = false;
    $data['sql'] = "uid=$uid";
  } finally {
    ws_verbose_logging($client, $response, $data, $verbose);
  }
  return $response;
}

function ws_verbose_logging($client, $response, &$data, $verbose) {
  if ($verbose >= 11 && !empty($client)) {
    print_r($client->__getLastRequestHeaders());
    print_r($client->__getLastRequest());
  }
  if ($verbose >= 10) {
    if (!empty($client)) $data['client'] = $client;
    $data['response'] = $response;
  }
  if ($verbose >= 12) {
    print_r($response);
  }
}


function fillDataFromUidBfsResult50($object, &$data) {
  if (!empty((array)$object)) {
    if (is_array($object->organisationType ?? null)) {
      $ot = $object->organisationType[0];
      $data['count'] = count($object->organisationType);
    } else if (!empty($object->organisationType)) {
      $ot = $object->organisationType;
      $data['count'] = 1;
    } else if (!empty($object->organisation)) {
      $ot = $object->organisation;
      $data['count'] = 1;
    } else {
      $ot = $object;
      $data['count'] = 1;
    }
    $oid = $ot->organisation->organisationIdentification;
    /*
    commercialRegisterStatus (eCH-0108:commercialRegisterStatusType)
    Status des Unternehmens im Handelsregister
      1 = unbekannt (kommt im UID-Register nicht vor!)
      2 = im HR eingetragen
      3 = nicht im HR eingetragen
      null = keine Einschränkung
    */
    $hr_status_code = $ot->commercialRegisterInformation->commercialRegisterStatus ?? null;
    /*
    commercialRegisterEntryStatus (eCH-0108:commercialRegisterEntryStatusType)
    Status des Eintrags im HR
      1 = aktiv
      2 = gelöscht
      3 = provisorisch
      null = keine Einschränkung
    */
    $hr_status_entry_code = $ot->commercialRegisterInformation->commercialRegisterEntryStatus ?? null;
    /*
    uidregStatusEnterpriseDetail (eCH-0108:uidregStatusEnterpriseDetailType)
      1 = provisorisch (UID durch das Unternehmensregister zugewiesen aber noch nicht geprüft)
      2 = in Reaktivierung (Reaktivierung eines vormals gelöschten Eintrags)
      3 = definitiv (Eintrag geprüft und UID zugewiesen)
      4 = in Mutation (Mutation eines bereits existierenden Eintrags)
      5 = gelöscht (Löschung eines Eintrags)
      6 = definitiv gelöscht (Löschung eines Eintrags nach Ablauf der 10 jährigen Aufbewahrungsfrist)
      7 = annulliert (wenn eine Dublette bei der Kontrolle entdeckt wurde)
    */
    $uid_status_code = $ot->uidregInformation->uidregStatusEnterpriseDetail;
    /*
    uidregPublicStatus (eCH-0108:uidregPublicStatusType)
    Ermöglicht die Eingrenzung auf öffentliche/nicht öffentliche UID-Einheiten
      true = Es werden nur öffentliche UID-Einheiten gesucht
      false = Es werden nur nicht-öffentliche UID-Einheiten gesucht
      null = keine Einschränkung
    */
    $uid_public_status_code = $ot->uidregInformation->uidregPublicStatus;
    $uid_ws = $oid->uid->uidOrganisationId;
    $base_address = $ot->organisation->address;
    $address = is_array($base_address) ? $base_address[0] : $base_address;
    $address2 = is_array($base_address) && isset($base_address[1]) ? $base_address[1] : null;
    $alte_hr_id = null;
    $ehra_id = null;
    if (!empty($oid->OtherOrganisationId) && is_array($oid->OtherOrganisationId)) {
      foreach ($oid->OtherOrganisationId as $otherOrg) {
        if ($otherOrg->organisationIdCategory === 'CH.HR') {
          $alte_hr_id = $otherOrg->organisationId;
        } elseif ($otherOrg->organisationIdCategory === 'CH.EHRAID') {
          $ehra_id = $otherOrg->organisationId;
        }
      }
    }
    $legel_form = !empty($oid->legalForm) ? $oid->legalForm : null;
    if (!empty($address->street)) {
      $adresse_strasse = trim($address->street) . (!empty($address->houseNumber) ? ' ' . trim($address->houseNumber) : '');
      $adresse_zusatz = !empty($address->addressLine1) ? trim($address->addressLine1) : (!empty($address2->postOfficeBoxNumber) ? 'Postfach ' . trim($address2->postOfficeBoxNumber) : null);
    } else {
      $adresse_strasse = !empty($address->addressLine1) ? trim($address->addressLine1) : null;
      $adresse_zusatz = !empty($address->addressLine2) ? trim($address->addressLine2) : null;
    }
    $data['data'] = array(
      'uid' => formatUID($uid_ws),
      'uid_raw' => $uid_ws,
      'alte_hr_id' => $alte_hr_id,
      'ch_id' => $alte_hr_id,
      'ehra_id' => $ehra_id,
      'name' => clean_str($oid->organisationName),
      'name_de' => clean_str($oid->organisationName),
      'alias_name' => clean_str($oid->organisationAdditionalName ?? null),
      'abkuerzung_de' => extractAbkuerzung($oid->organisationName),
      // TODO 'name_fr' => $ot->organisation->organisationIdentification->organisationName,
      'rechtsform_handelsregister' => $legel_form,
      'rechtsform' => _lobbywatch_ws_get_rechtsform($legel_form),
      'adresse_strasse' => clean_str($adresse_strasse),
      'adresse_zusatz' => clean_str($adresse_zusatz),
      'ort' => clean_str($address->town),
      'bfs_gemeinde_nr' => !empty($address->municipalityId) && is_numeric($address->municipalityId) ? +$address->municipalityId : null,
      'eidg_gebaeude_id_egid' => $address->EGID ?? null,
      'adresse_plz' => $address->swissZipCode ?? $address->foreignZipCode ?? null,
      'land_iso2' => $address->countryIdISO2,
      'land_id' => _lobbywatch_ws_get_land_id($address->countryIdISO2),
      //     'handelsregister_url' => ,
      'register_kanton' => null,
      'kanton' => $address->cantonAbbreviation ?? null,
      'inaktiv' => (!empty($uid_status_code) ? in_array($uid_status_code, [5, 6, 7]) : null) || (!empty($hr_status_entry_code) ? $hr_status_entry_code == 2 : (!empty($ot->organisation->liquidation->uidregLiquidationDate) ? true : null)),
      'in_handelsregister' => $hr_status_code == 2,
      'gruendungsdatum' => $ot->organisation->foundationDate ?? null,
      'uid_nachfolger' => $ot->uidregInformation->uidReplacement ?? null,
    );
    return $data['data'];
  } else {
    $data['message'] .= 'Nothing found';
    $data['success'] = false;
    return false;
  }
}

function _lobbywatch_ws_get_land_id($iso2) {
  $table = 'country';
  $ret = null;
  try {
    $sql = "
      SELECT id
      FROM v_$table $table
      WHERE $table.`iso2`=:iso2";

    $result = db_query($sql, array(':iso2' => $iso2));

    $items = $result[0];
    $ret = $items;
  } catch (Exception $e) {
    $ret = null;
  }
  return $ret;
}

function _lobbywatch_ws_get_rechtsform($rechtsform_handelsregister) {
  switch ($rechtsform_handelsregister) {
    //  Rechtsformen des Privatrechts, im Handelsregister angewendet
    case '0101':
      $val = 'Einzelunternehmen';
      break; // 0101 Einzelunternehmen
    case '0103':
      $val = 'KG';
      break; // 0103 Kollektivgesellschaft
    // 0104 Kommanditgesellschaft
    // 0105 Kommanditaktiengesellschaft
    case '0106':
      $val = 'AG';
      break; // 0106 Aktiengesellschaft
    case '0107':
      $val = 'GmbH';
      break; // 0107 Gesellschaft mit beschränkter Haftung GMBH / SARL
    case '0108':
      $val = 'Genossenschaft';
      break; // 0108 Genossenschaft
    case '0109':
      $val = 'Verein';
      break; // 0109 Verein (hier werden auch staatlich anerkannte Kirchen geführt)
    case '0110':
      $val = 'Stiftung';
      break; // 0110 Stiftung
    // 0111 Ausländische Niederlassung im Handelsregister eingetragen
    // 0113 Besondere Rechtsform Rechtsformen, die unter keiner anderen Kategorie aufgeführt werden können.
    // 0114 Kommanditgesellschaft für kollektive Kapitalanlagen
    // 0115 Investmentgesellschaft mit variablem Kapital (SICAV)
    // 0116 Investmentgesellschaft mit festem Kapital (SICAF)
    case '0117':
      $val = 'Oeffentlich-rechtlich';
      break; // 0117 Institut des öffentlichen Rechts
    // 0118 Nichtkaufmännische Prokuren
    // 0119 Haupt von Gemeinderschaften
    // 0151 Schweizerische Zweigniederlassung im Handelsregister eingetragen
    //  Rechtsformen des öffentlichen Rechts, nicht im Handelsregister angewendet
    case '0220':
      $val = 'Staatlich';
      break; // 0220 Verwaltung des Bundes
    case '0221':
      $val = 'Staatlich';
      break; // 0221 Verwaltung des Kantons
    case '0222':
      $val = 'Staatlich';
      break; // 0222 Verwaltung des Bezirks
    case '0223':
      $val = 'Staatlich';
      break; // 0223 Verwaltung der Gemeinde
    case '0224':
      $val = 'Staatlich';
      break; // 0224 öffentlich-rechtliche Körperschaft (Verwaltung) Hier werden die öffentlich-rechtlichen Körperschaften aufgeführt, die nicht un-  ter den Punkten Verwaltung des Bundes, des Kantons, des Bezirks oder der  Gemeinde aufgelistet werden können. Z.B. Gemeindeverbände, Schulge-  meinden, Kreise und von mehreren Körperschaften geführte Verwaltungen.
    case '0230':
      $val = 'Staatlich';
      break; // 0230 Unternehmen des Bundes
    case '0231':
      $val = 'Staatlich';
      break; // 0231 Unternehmen des Kantons
    case '0232':
      $val = 'Staatlich';
      break; // 0232 Unternehmen des Bezirks
    case '0233':
      $val = 'Staatlich';
      break; // 0233 Unternehmen der Gemeinde
    case '0234':
      $val = 'Oeffentlich-rechtlich';
      break; // 0234 öffentlich-rechtliche Körperschaft (Unternehmen) Hierzu zählen alle öffentlich-rechtlichen Unternehmen, die nicht unter den  Punkten Unternehmen des Bundes, des Kantons, des Bezirks oder der Ge-  meinde ausgelistet werden können, z.B. die Forstbetriebe von Ortsbürgerge-  meinden.
    //  Andere  Rechtsformen nicht im Handelsregister angewendet
    case '0302':
      $val = 'Einfache Gesellschaft';
      break; // 0302 Einfache Gesellschaft
    // 0312 Ausländische Niederlassung nicht im Handelsregister eingetragen
    // 0327 Ausländisches öffentliches Unternehmen  Staatlich geführte ausländische Unternehmen, z.B. Niederlassungen von aus-  ländischen Eisenbahnen und Tourismusbehörden.
    // 0328 Ausländische öffentliche Verwaltung  Insbesondere Botschaften, Missionen und Konsulate.
    // 0329 Internationale Organisation
    //  Ausländische Unternehmen
    // 0441 Ausländische Unternehmen (Entreprise étrangère, impresa straniera)

    default:
      $val = null;
  }
  return $val;
}


/** Extracts (aaa) or (AAA) or AAA. */
function extractAbkuerzung(?string $str): ?string {
  if (empty($str)) return null;
  if (preg_match('%\(([a-z]{3}|[A-ZÄÖÜ]{3,4})\)%', $str, $matches)) {
    return $matches[1];
  } else if (mb_strtoupper($str) !== $str && preg_match('%\b([A-Z]{3})\b(?!-)%', $str, $matches)) {
    return $matches[1];
  } else {
    return null;
  }
}

function getUIDnumber($uid_raw) {
  $matches = [];

  if (is_numeric($uid_raw) && strlen($uid_raw) == 9) {
    return $uid_raw;
  }

  $formatted_uid = formatUID($uid_raw);
  if (preg_match('/^CHE-(\d{3})[.](\d{3})[.](\d{3})$/', $formatted_uid, $matches)) {
    $uid = $matches[1] . $matches[2] . $matches[3];
  } else if (preg_match('/^CHE(\d{9})$/', $formatted_uid, $matches)) {
    $uid = $matches[1];
  } else {
    $uid = null;
  }

  return $uid;
}

function formatUID($uid_raw) {
  $matches = [];
  if (is_numeric($uid_raw) && strlen($uid_raw) == 8) {
    $check_digit = _lobbywatch_calculate_uid_check_digit($uid_raw);
    $uid_raw .= $check_digit;
    $uid = formatUIDnumber($uid_raw);
  } else if (preg_match('/^CHE-(\d{3}[.]\d{3}[.]\d{2})$/', $uid_raw, $matches)) {
    $uid_raw = str_replace('.', '', $matches[1]);
    $check_digit = _lobbywatch_calculate_uid_check_digit($uid_raw);
    $uid_raw .= $check_digit;
    $uid = formatUIDnumber($uid_raw);
  } else if (is_numeric($uid_raw) && strlen($uid_raw) == 9) {
    $uid = formatUIDnumber($uid_raw);
  } else if (preg_match('/^CHE(\d{9})$/', $uid_raw, $matches)) {
    $uid = formatUIDnumber($matches[1]);
  } else if (preg_match('/^CHE-\d{3}[.]\d{3}[.]\d{3}$/', $uid_raw, $matches)) {
    $uid = $matches[0];
  } else {
    $uid = null;
  }
  return $uid;
}

function formatUIDnumber($uid_number) {
  if (!is_numeric($uid_number) || strlen($uid_number) != 9) {
    throw new Exception("Not an UID number: $uid_number");
  }
  return 'CHE-' . substr($uid_number, 0, 3) . '.' . substr($uid_number, 3, 3) . '.' . substr($uid_number, 6, 3);
}

function getUidBfsWsLogin($test_mode = false) {
  if ($test_mode) {
    $host = 'www.uid-wse-a.admin.ch';
  } else {
    $host = 'www.uid-wse.admin.ch';
  }
  // https://www.uid-wse.admin.ch/V5.0/PublicServices.svc?wsdl
  $wsdl = "https://$host/V5.0/PublicServices.svc?wsdl";
  $response = array(
    'wsdl' => $wsdl,
    'login' => null,
    'password' => null,
    'host' => $host,
  );
  return $response;
}

function _lobbywatch_check_uid_format($uid_raw, &$uid, &$message) {
  $matches = [];
  $success = true;
  if (preg_match('/^CHE-(\d{3})\.(\d{3}).(\d{3})$/', $uid_raw, $matches)) {
    $uid = $matches[1] . $matches[2] . $matches[3];
  } else if (preg_match('/^(\d{9})$/', $uid_raw, $matches)) {
    $uid = $matches[1];
  } else if (preg_match('/^CHE(\d{9})$/', $uid_raw, $matches)) {
    $uid = $matches[1];
  } else {
    $message = "Wrong UID format: $uid_raw, correct: (9-digits or CHE-000.000.000)";
    $success = false;
  }
  return $success;
}

function _lobbywatch_check_uid_check_digit($uid_number, &$message) {
  $uid_check_digit = substr($uid_number, 8, 1);
  $check_digit = _lobbywatch_calculate_uid_check_digit($uid_number);

  if ($uid_check_digit != $check_digit || $check_digit == 10) {
    $message = "Wrong UID check digit: $uid_check_digit, correct: $check_digit" /*. ", sum=$dot_product"*/
    ;
    return false;
  }
  return true;
}

function _lobbywatch_calculate_uid_check_digit($uid_number) {
  if (!is_numeric($uid_number) || strlen($uid_number) < 8 || strlen($uid_number) > 9) {
    return null;
  }
  $digits = str_split(substr($uid_number, 0, 8));
  $weight = array(5, 4, 3, 2, 7, 6, 5, 4);
  // http://c2.com/cgi/wiki?DotProductInManyProgrammingLanguages
  $dot_product = array_sum(array_map(function ($a, $b) {
    return $a * $b;
  }, $digits, $weight));
  $check_digit = 11 - ($dot_product % 11);
  $check_digit = $check_digit == 11 ? 0 : $check_digit;

  return $check_digit;
}


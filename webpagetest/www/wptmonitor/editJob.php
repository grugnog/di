<?php
  require("login/login.php");
  include 'monitor.inc';
  $ownerId="";
  $userId = getCurrentUserId();
  if ( isset($_REQUEST['id']) ){
    $jobId = $_REQUEST['id'];
    $ownerId = getOwnerIdFor($jobId, 'WPTJob');
    $currentJobCount = getUserJobCount($userId);
    $maxJobsPerMonth = getMaxJobsPerMonth($userId);
    $folderId = getFolderIdFor($jobId, 'WPTJob');
  } else {
    if ( isset($_REQUEST['folderId'])){
      $folderId = $_REQUEST['folderId'];
    } else {
      $folderId = getRootFolderForUser($userId,'WPTJob');
    }
  }

  if (!hasPermission('WPTJob', $folderId, PERMISSION_UPDATE)) {
    echo "Invalid Permission";
    exit;
  }
  // Folder shares for the Alerts
  $folderShares = getFolderShares($userId, 'Alert');
  $alertFolderIds = array();
  foreach ($folderShares as $key => $folderShare) {
    foreach ($folderShare as $k => $share) {
      $alertFolderIds[] = $k;
    }
  }
  // Scripts
  $folderShares = getFolderShares($userId, 'WPTScript');
  $scriptFolderIds = array();
  foreach ($folderShares as $key => $folderShare) {
    foreach ($folderShare as $k => $share) {
      $scriptFolderIds [] = $k;
    }
  }

  $wptLocations = getWptLocations();
  $wptLocs = array();
  foreach ($wptLocations as $loc) {
    $key = $loc['Location'];
    $wptLocs[$loc->WPTHost['HostURL'] . ' ' . $key] = $loc->WPTHost['HostURL'] . ' ' . $key.' '.$loc['Label'];
  }

  // Support for new multiple locations side by sideselect box
  $wptAvailLocations = array();
  $tmpLoc = array();
  foreach ($wptLocations as $loc) {
    $key = $loc['Location'];
//    $wptLocs[$loc->WPTHost['HostURL'] . ' ' . $key] = $loc->WPTHost['HostURL'] . ' ' . $key.' '.$loc['Label'];

    $wptLocs[$loc->WPTHost['HostURL'] . ' ' . $key] = $loc['Label'];
  }
  if (isset($jobId)) {
    $q = Doctrine_Query::create()->from('WPTJob j')->where('j.Id= ?', $jobId);
    $result = $q->fetchOne();
    $q->free(true);
    $scriptId = $result['WPTScript']['Id'];
//    $smarty->assign('selectedLocation', $result['Host'] . ' ' . $result['Location']);
  } else {
    $result = new WPTJob();
    $result['Frequency'] = 60;
    $smarty->assign('selectedLocation', '');
  }
  if (isset($scriptId)){
  $scriptFolderId  = getFolderIdFor($scriptId,'WPTScript');

  $canChangeScript = hasPermission('WPTScript',$scriptFolderId, PERMISSION_UPDATE);
  } else {
    $canChangeScript = true;
  }
  $smarty->assign('canChangeScript',$canChangeScript);

  if (!$result['WPTBandwidthDown'])
    $result['WPTBandwidthDown'] = 1500;
  if (!$result['WPTBandwidthUp'])
    $result['WPTBandwidthUp'] = 384;
  if (!$result['WPTBandwidthLatency'])
    $result['WPTBandwidthLatency'] = 50;
  if (!$result['WPTBandwidthPacketLoss'])
    $result['WPTBandwidthPacketLoss'] = 0;

  $q = Doctrine_Query::create()->from('WPTScript s')->orderBy('s.Label');
  if ($folderId > -1 && hasPermission('WPTScript', $folderId, PERMISSION_UPDATE)) {
    $q->andWhereIn('s.WPTScriptFolderId', $scriptFolderIds);
  } else {
    $q->andWhere('s.UserId = ?', $userId);
  }

  $scripts = $q->fetchArray();
  $q->free(true);
  $scriptArray = array();
  foreach ($scripts as $script) {
    $id = $script['Id'];
    $scriptArray[$id] = $script['Label'];
  }
  // Fetch alerts for this job
  $q = Doctrine_Query::create()->from('Alert a')->orderBy('a.Label');

  if (!empty($alertFolderIds) && $folderId > -1 && hasPermission('Alert', $folderId, PERMISSION_UPDATE)) {
    $q->andWhereIn('a.AlertFolderId', $alertFolderIds);
  } else {
    $q->andWhere('a.UserId = ?', $userId);
  }
  $alerts = $q->fetchArray();
  $q->free(true);
  $alertArray = array();
  $alert = array();
  foreach ($alerts as $a) {
    $idx = $a['Id'];
    $alert['Id'] = $a['Id'];
    $alert['Label'] = $a['Label'];
    $alert['Active'] = $a['Active'];
    $alert['Selected'] = 0;
    $alertArray[$idx] = $alert;
  }
  $q = Doctrine_Query::create()->from('WPTJob_Alert a');
  if (isset($jobId)){
    $q->where('a.WPTJobId= ?', $jobId);
  }
  $selectedAlerts = $q->fetchArray();
  $q->free(true);
  foreach ($selectedAlerts as $selected) {
    $aid = $selected['AlertId'];
    if ($a = $alertArray[$aid]['Id']) {
      $alertArray[$aid]['Selected'] = 1;
    }
  }

// Fetch locations for this job
  $q = Doctrine_Query::create()->from('WPTLocation l')->orderBy('l.Label');
  $locations = $q->fetchArray();
  $q->free(true);
  $locationArray = array();
  $location = array();
  foreach ($locations as $l) {
    $idx = $l['Id'];
    $location['Id'] = $l['Id'];
    $location['Label'] = $l['Label'];
    $location['Browser'] = $l['Browser'];
    $location['Active'] = $l['Active'];
    $location['Selected'] = 0;
    $locationArray[$idx] = $location;
  }

  $q = Doctrine_Query::create()->from('WPTJob_WPTLocation l');
  if (isset($jobId)){
    $q->where('l.WPTJobId= ?', $jobId);
    $selectedLocations = $q->fetchArray();
  }
  $q->free(true);
  if ( isset($selectedLocations)){
    foreach ($selectedLocations as $selected) {
      $lid = $selected['WPTLocationId'];
      if ($a = $locationArray[$lid]['Id']) {
        $locationArray[$lid]['Selected'] = 1;
      }
    }
  }


  // Set vars for smarty
  if(!isset($maxJobsPerMonth)){
    $maxJobsPerMonth="";
  }
  if(!isset($currentJobCount)){
    $currentJobCount="";
  }
  $folderTree = getFolderTree($userId, 'WPTJob');
  $shares = getFolderShares($userId, 'WPTJob');
  $smarty->assign('folderTree', $folderTree);
  $smarty->assign('shares', $shares);
  $smarty->assign('folderId', $folderId);
  $smarty->assign('alerts', $alertArray);
  $smarty->assign('locations', $locationArray);
  $smarty->assign('maxJobsPerMonth', $maxJobsPerMonth);
  $smarty->assign('currentJobCount', $currentJobCount);
  $smarty->assign('job', $result);
  $smarty->assign('ownerId', $ownerId);
  $smarty->assign('scripts', $scriptArray);
  $smarty->assign('wptLocations', $wptLocs);
  $smarty->display('job/addJob.tpl');
?>
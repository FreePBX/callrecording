<?php

/* TODO:
 *
 * - Add outbound routes force recording (see pinssets for example similar code
 * - Move Extension Recording sections from core to here and add as hook
 *   see languages for similar code to implement
 * - Move the common macros from core to here
 * - Make functionality in other modules conditional on this stuff being here or if not
 *   overly complex, maybe move some of their functionality into hooks provdied from here
 */
function callrecording_destinations() {
	global $module_page;

	// it makes no sense to point at another queueprio (and it can be an infinite loop)
	if ($module_page == 'callrecording') {
		return false;
	}

	// return an associative array with destination and description
	foreach (callrecording_list() as $row) {
		$extens[] = array('destination' => 'ext-callrecording,' . $row['callrecording_id'] . ',1', 'description' => $row['description']);
	}
	return isset($extens)?$extens:null;
}

function callrecording_getdest($exten) {
	return array('ext-callrecording,'.$exten.',1');
}

function callrecording_getdestinfo($dest) {
	global $active_modules;

	if (substr(trim($dest),0,14) == 'ext-callrecording,') {
		$exten = explode(',',$dest);
		$exten = $exten[1];
		$thisexten = callrecording_get($exten);
		if (empty($thisexten)) {
			return array();
		} else {
			$type = isset($active_modules['callrecording']['type'])?$active_modules['callrecording']['type']:'setup';
			return array('description' => sprintf(_("Call Recording: %s"),$thisexten['description']),
			             'edit_url' => 'config.php?display=callrecording&type='.$type.'&extdisplay='.urlencode($exten),
								  );
		}
	} else {
		return false;
	}
}

function callrecording_get_config($engine) {
	global $ext;
	switch ($engine) {
		case 'asterisk':
      $context = 'ext-callrecording';
			foreach (callrecording_list() as $row) {
				$ext->add($context, $row['callrecording_id'], '', new ext_noop_trace('Call Recording: [' . $row['callrecording_mode'] . '] Event'));
        switch ($row['callrecording_mode']) {
        case 'force':
          $ext->add($context, $row['callrecording_id'], '', new ext_gosub('1','s','sub-record-check','force,${FROM_DID},always'));
        break;
        case 'delayed':
				  $ext->add($context, $row['callrecording_id'], '', new ext_set('__REC_POLICY_MODE','always'));
        break;
        case 'never':
          $ext->add($context, $row['callrecording_id'], '', new ext_gosub('1','s','sub-record-cancel'));
				  $ext->add($context, $row['callrecording_id'], '', new ext_set('__REC_POLICY_MODE','never'));
        break;
        default: // allowed
          $ext->add($context, $row['callrecording_id'], '', new ext_execif('$["${REC_POLICY_MODE}"="never"]','Set','__REC_POLICY_MODE='));
        break;
        }
				$ext->add($context, $row['callrecording_id'], '', new ext_goto($row['dest']));
			}
		
		break;
	}
}

function callrecording_hookGet_config($engine) {
	global $ext;
	global $version;
	switch($engine) {
		case "asterisk":
			
			$routes=callrecording_display_get('did');
			foreach($routes as $current => $route){
				if($route['extension']=='' && $route['cidnum']){//callerID only
					$extension='s/'.$route['cidnum'];
					$context=$route['pricid']?'ext-did-0001':'ext-did-0002';
				}else{
					if(($route['extension'] && $route['cidnum'])||($route['extension']=='' && $route['cidnum']=='')){//callerid+did / any/any
						$context='ext-did-0001';
					}else{//did only
						$context='ext-did-0002';
					}
					$extension=($route['extension']!=''?$route['extension']:'s').($route['cidnum']==''?'':'/'.$route['cidnum']);
				}
        // These are splices, so they "reverse stack" and will end up in opposite order
        //
        switch ($route['callrecording']) {
        case 'force':
          $ext->splice($context, $extension, '1', new ext_gosub('1','s','sub-record-check','force,${EXTEN},always'));
        break;
        case 'delayed':
				  $ext->splice($context, $extension, '1', new ext_set('__REC_POLICY_MODE','always'));
        break;
        case 'never':
				  $ext->splice($context, $extension, '1', new ext_set('__REC_POLICY_MODE','never'));
          $ext->splice($context, $extension, '1', new ext_gosub('1','s','sub-record-cancel'));
        break;
        default: // allowed
          // Nothing to do here since it is an inbound route, first thing we hit
        break;
        }
      $ext->splice($context, $extension, 1, new ext_noop_trace('Call Recording: [' . $route['callrecording'] . '] Event'));
		}
		break;
	}
}

/**  Get a list of all callrecording
 */
function callrecording_list() {
	global $db;
	$sql = "SELECT callrecording_id, description, callrecording_mode, dest FROM callrecording ORDER BY description ";
	$results = $db->getAll($sql, DB_FETCHMODE_ASSOC);
	if(DB::IsError($results)) {
		die_freepbx($results->getMessage()."<br><br>Error selecting from callrecording");	
	}
	return $results;
}

function callrecording_get($callrecording_id) {
	global $db;
	$sql = "SELECT callrecording_id, description, callrecording_mode, dest FROM callrecording WHERE callrecording_id = ".$db->escapeSimple($callrecording_id);
	$row = $db->getRow($sql, DB_FETCHMODE_ASSOC);
	if(DB::IsError($row)) {
		die_freepbx($row->getMessage()."<br><br>Error selecting row from callrecording");	
	}
	
	return $row;
}

function callrecording_add($description, $callrecording_mode, $dest) {
	global $db;
	$sql = "INSERT INTO callrecording (description, callrecording_mode, dest) VALUES (".
		"'".$db->escapeSimple($description)."', ".
		"'".$db->escapeSimple($callrecording_mode)."', ".
		"'".$db->escapeSimple($dest)."')";
	$result = $db->query($sql);
	if(DB::IsError($result)) {
		die_freepbx($result->getMessage().$sql);
	}
}

function callrecording_delete($callrecording_id) {
	global $db;
	$sql = "DELETE FROM callrecording WHERE callrecording_id = ".$db->escapeSimple($callrecording_id);
	$result = $db->query($sql);
	if(DB::IsError($result)) {
		die_freepbx($result->getMessage().$sql);
	}
}

function callrecording_edit($callrecording_id, $description, $callrecording_mode, $dest) { 
	global $db;
	$sql = "UPDATE callrecording SET ".
		"description = '".$db->escapeSimple($description)."', ".
		"callrecording_mode = '".$db->escapeSimple($callrecording_mode)."', ".
		"dest = '".$db->escapeSimple($dest)."' ".
		"WHERE callrecording_id = ".$db->escapeSimple($callrecording_id);
	$result = $db->query($sql);
	if(DB::IsError($result)) {
		die_freepbx($result->getMessage().$sql);
	}
}

function callrecording_hook_core($viewing_itemid, $target_menuid){
	$extension	= isset($_REQUEST['extension'])		? $_REQUEST['extension']	:'';
	$cidnum		= isset($_REQUEST['cidnum'])		? $_REQUEST['cidnum']		:'';
	$extdisplay	= isset($_REQUEST['extdisplay'])	? $_REQUEST['extdisplay']	:'';
	$action		= isset($_REQUEST['action'])		? $_REQUEST['action']		:'';
	$callrecording	= isset($_REQUEST['callrecording'])		? $_REQUEST['callrecording']		:'';
	//set $extension,$cidnum if we dont already have them
	if(!$extension && !$cidnum){
		$opts		= explode('/', $extdisplay);
		$extension	= $opts['0'];
		$cidnum		= isset($opts['1']) ? $opts['1'] : '';
	}else{
		$extension 	= $extension;
		$cidnum		= $cidnum;
	}
	
	//update if we have enough info
	if($action == 'edtIncoming' || ( $extension != '' || $cidnum != '') && $callrecording != ''){
		callrecording_display_update('did',$callrecording=$callrecording,$extension,$cidnum);
	}
	if($action=='delIncoming'){
		callrecording_display_delete('did',$extension,$cidnum);
	}
	$html = '';
	if ($target_menuid == 'did'){
    global $tabindex;
		$callrecording = callrecording_display_get('did',$extension,$cidnum);

		$html.='<tr><td colspan="2"><h5>'._("Call Recording").'<hr></h5></td></tr>';
		$html.='<tr><td><a href="#" class="info">'._('Call Recording').'<span>'._("Controls or overrides the call recording behavior for calls coming into this DID. Allow will honor the normal downstream call recording settings. Record on Answer starts recording when the call would otherwise be recorded ignoring any settings that say otherwise. Record Immediately will start recording right away capturing ringing, announcements, MoH, etc. Never will disallow recording regardless of downstream settings.").'</span></a>:</td>';
		$html.='<td><select name="callrecording" tabindex="' . ++$tabindex . '">'."\n";
    $html.= '<option value=""' . ($callrecording == ''  ? ' SELECTED' : '').'>'._("Allow")."\n";
    $html.= '<option value="delayed"'. ($callrecording == 'delayed' ? ' SELECTED' : '').'>'._("Record on Answer")."\n";
    $html.= '<option value="force"'  . ($callrecording == 'force'   ? ' SELECTED' : '').'>'._("Record Immediately")."\n";
    $html.= '<option value="never"' . ($callrecording == 'never'  ? ' SELECTED' : '').'>'._("Never")."\n";
    $html.= "</select></td></tr>\n";
	}
	return $html;
}


function callrecording_display_get($display, $extension=null,$cidnum=null){
	global $db;
	if($extension || $cidnum || (isset($_REQUEST['extdisplay']) && $_REQUEST['extdisplay']=='/') || (isset($_REQUEST['display']) && $_REQUEST['display']=='did')){
		$sql='SELECT callrecording FROM callrecording_module WHERE display = ? AND extension = ? AND cidnum = ?';
		$mode=$db->getOne($sql, array($display, $extension, $cidnum));
	}else{
		$sql="SELECT callrecording_module.*,incoming.pricid FROM callrecording_module, incoming WHERE callrecording_module.cidnum=incoming.cidnum AND callrecording_module.extension=incoming.extension AND callrecording_module.display = '$display'";
		$mode=$db->getAll($sql, DB_FETCHMODE_ASSOC);
	}
	return $mode;
}

function callrecording_display_update($display,$recording_code=null,$extension=null,$cidnum=null){
	global $db;
	$sql="DELETE FROM callrecording_module WHERE display = ? AND extension = ? AND cidnum = ?";
	$db->query($sql,array($display,$extension,$cidnum));
	if(isset($recording_code) && $recording_code!=''){
		$sql="INSERT INTO callrecording_module (display,extension,cidnum,callrecording) VALUES (?, ?, ?,?)";
		$db->query($sql,array($display,$extension,$cidnum,$recording_code));
	};
}

function callrecording_display_delete($display,$extension=null,$cidnum=null){
	global $db;
  $sql="DELETE FROM callrecording_module WHERE display = ? AND extension = ? AND cidnum = ?";
  $db->query($sql,array($display,$extension,$cidnum));
}

function callrecording_check_destinations($dest=true) {
	global $active_modules;

	$destlist = array();
	if (is_array($dest) && empty($dest)) {
		return $destlist;
	}
	$sql = "SELECT callrecording_id, dest, description FROM callrecording ";
	if ($dest !== true) {
		$sql .= "WHERE dest in ('".implode("','",$dest)."')";
	}
	$results = sql($sql,"getAll",DB_FETCHMODE_ASSOC);

	$type = isset($active_modules['callrecording']['type'])?$active_modules['callrecording']['type']:'setup';

	foreach ($results as $result) {
		$thisdest = $result['dest'];
		$thisid   = $result['callrecording_id'];
		$destlist[] = array(
			'dest' => $thisdest,
			'description' => sprintf(_("Call Recording: %s"),$result['description']),
			'edit_url' => 'config.php?display=callrecording&type='.$type.'&extdisplay='.urlencode($thisid),
		);
	}
	return $destlist;
}

function callrecording_change_destination($old_dest, $new_dest) {
	$sql = 'UPDATE callrecording SET dest = "' . $new_dest . '" WHERE dest = "' . $old_dest . '"';
	sql($sql, "query");
}
?>

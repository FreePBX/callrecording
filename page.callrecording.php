<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//  Copyright 2006 Philippe Lindheimer - Astrogen LLC
//	Copyright 2013 Schmooze Com Inc.
$request = $_REQUEST;
$heading = _("Call Recording");
//get unique queues
switch($request["view"]){
	case "form":
		if($request['extdisplay']){
			$heading .= _(": Edit");
		}else{
			$heading .= _(": Add");
		}
		$content = load_view(__DIR__.'/views/form.php', array('request' => $request, 'amp_conf' => $amp_conf));
	break;
	default:
		$content = load_view(__DIR__.'/views/grid.php');
	break;
}

?>
<div class="container-fluid">
	<h1><?php echo $heading ?></h1>
	<div class="well">
		<p><?php echo("Call Recordings provide the ability to force a call to be recorded or not recorded based on a call flow and override all other recording settings. If a call is to be recorded, it can start immediately which will incorporate any announcements, hold music, etc. prior to being answered, or it can have recording start at the time that call is answered.")?></p>
	</div>
	<div class = "display full-border">
		<div class="row">
			<div class="col-sm-12">
				<div class="fpbx-container">
					<div class="display full-border">
						<?php echo $content ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>







<script language="javascript">
<!--

function checkCallRecording(theForm) {
	var msgInvalidDescription = "<?php echo _('Invalid description specified'); ?>";

	// set up the Destination stuff
	setDestinations(theForm, '_post_dest');

	// form validation
	defaultEmptyOK = false;
	if (isEmpty(theForm.description.value))
		return warnInvalid(theForm.description, msgInvalidDescription);

	if (!validateDestinations(theForm, 1, true))
		return false;

	return true;
}
//-->
</script>

<?php

$config = parse_ini_file('orcidReport.ini');

// Initialize resource-aip-list file selector
global $config;
$orcidReportLoc = $config['reportDir'];
$orcidReportDirectory = opendir($orcidReportLoc);
while($entryName = readdir($orcidReportDirectory)) {
    if (substr($entryName,0,10) == "orcidUsers"){
    	$orcidReportDirArray[] = $entryName;
    }
}
closedir($orcidReportDirectory);

// Check for download arguments
testArgs();

header('Content-type: text/html; charset=UTF-8');
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<link rel="stylesheet" type="text/css" href="orcidReport.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.1.0/jquery.min.js"></script>
<script src="orcidReport.js"></script>
</head>
<body>
<div id="formDownloadReport">
  <form method="POST" action="">

    <p>
      <fieldset class="reportList">
        <legend>Download an Existing ORCID User Report</legend>
        <p>
          <label for="reportList">ORCID Report</label>
          <select name="reportList" id="reportList">
          <option value="">Select Report for Download</option>
          <?php
          	rsort($orcidReportDirArray);
            foreach($orcidReportDirArray as $fname) {
            	echo "<option value='" . $fname . "'>" . getPrettyName($fname) . "</option>";
            }
          ?>
          </select>
        </p>
        
        <p align="center">
      	<input id="submit" type="submit" value="Download Report"/>
    	</p>
      </fieldset>
    </p>

  </form>
</div>

<?php
//create content for reports summary table and diffs summaries
sort($orcidReportDirArray);
$prevList = NULL;
//create data for each report in report summaries table and report diffs container; note: need to be processed in reverse in order to determine new users
foreach($orcidReportDirArray as $report) {
	$currList = getUsersFromCSV($report);
	$diffList = (isset($prevList)) ? getNewUsers($prevList,$currList) : array();
	$diffCount = (isset($prevList)) ? "<a href='#" . getReportID($report) . "'>+" . count($diffList) . "</a>" : "N/A";
	//add table row to reports table with name of report, total user count, and number of new users since previous report
	$reportRowString = "<tr>" .
					"<td>" . getPrettyName($report) . "</td>" .
					"<td>" . count($currList) . "</td>" . 
					"<td>" . $diffCount . "</td>" .
				 "</tr>";
	//if no new users, add simple message to diff views, otherwise add table of new users to differences
	$newUsersSummary = "<div class='diffContainer closed' id='" . getReportID($report) . "'><h4>New Users for " . getPrettyName($report) . " Report</h4>";
	if(count($diffList) == 0) {
		$newUsersSummary .= 	"<p>No new users in this report.</p>";
	}
	else {
		$newUsersSummary .= "<table><thead><tr class='header'><th>Name</th><th>Title</th><th>Designation</th></tr></thead>";
		foreach($diffList as $userInfo) {
			$newUsersSummary .= "<tr>" .
				//name (linked to ORCID profile)
				"<td><a href='" . $userInfo[1] . "' target='_blank'>$userInfo[3]</a></td>" .
				//title (limited to 30 chars.)
				"<td>" . (strlen($userInfo[4]) > 30 ? substr($userInfo[4],0,30) : $userInfo[4]) . "</td>" .
				//designation
				"<td>$userInfo[5]</td>" .
				"</tr>";
		}
		$newUsersSummary .= "</table>";
	}
	$newUsersSummary .= "</div>";
	
	$reportList[] = $reportRowString;
	$newUserDiffsList[] = $newUsersSummary;
	$prevList = $currList;
}
?>

<div id="reportCounts" class="tableBlock">
	<h4>Reports Summary</h4>
	<table id="countsTable">
		<thead><tr class='header'><th>Report</th><th>Researcher Count</th><th>Change</th></tr></thead>
			<?php
				//print report summary rows in reverse-chronological order
				for($i=count($reportList)-1;$i>=0;$i--) {
					echo $reportList[$i];
				}
			?>
	</table>
</div>

<div id="diffDisplays" class="tableBlock">
<?php
for($i=count($newUserDiffsList)-1;$i>=0;$i--) {
	echo $newUserDiffsList[$i];
}
?>
</div>
</body>
</html>
<?php 

//tries to return prettily formated date name; returns input name if fails
function getPrettyName($report) {
	$dateStartIndex = stripos($report,"_") + 1;
	$dateEndIndex = strrpos($report,"_");
	$timeStartIndex = $dateEndIndex + 1;
	$timeEndIndex = strrpos($report,"x");
	$dateS = substr($report,$dateStartIndex,$dateEndIndex-$dateStartIndex);
	$timeS = substr($report,$timeStartIndex,$timeEndIndex-$timeStartIndex);
	if(strlen($dateS) != 10) return $report;
	$format = "Y-m-d";
	$date = DateTime::createFromFormat($format,$dateS);
	$time = str_replace("-",":",$timeS);
	$prettyName = $date->format("M d, Y") . " (@$time)";
	return $prettyName;
}

//get reference ID for report
function getReportID($report) {
	$sIndex = stripos($report,"_") + 1;
	$eIndex = strrpos($report,"x");
	$dateS = substr($report,$sIndex,$eIndex-$sIndex);
	return $dateS;
}

//get prettily formatted download name for report
function getDownloadName($report) {
	$sIndex = stripos($report,"_") + 1;
	$eIndex = strrpos($report,"_");
	$dateS = substr($report,$sIndex,$eIndex-$sIndex);
	if(strlen($dateS) != 10) return "orcidReport.csv";
	$format = "Y-m-d";
	$date = DateTime::createFromFormat($format,$dateS);
	return "OrcidReport_" . $date->format("m-d-Y") . ".csv";
}

//returns array of ORCID info for users that are in $usersTwo but not $usersOne, or NULL if either array unset
function getNewUsers($usersOne,$usersTwo) {
	$toReturn = array();
	//return NULL if either report CSV is unreadable
	if(!isset($usersOne) || !isset($usersTwo)) return NULL;
	//for each user in the second report...
	for($i=0;$i<count($usersTwo);$i++) {
		$x = $usersTwo[$i][0];
		$match = FALSE;
		for($j=0;$j<count($usersOne);$j++) {
			//check if their ORCID ID is contained in first report
			if($x == $usersOne[$j][0]) {
				$match = TRUE;
				break;
			}
		}
		//if user's ORCID ID not in first report, add to return array as new user
		if($match === FALSE) {
			$toReturn[] = $usersTwo[$i];
		}
	}
	return $toReturn;
}

//returns two-dimensional array of user ORCID information read in from specified CSV
function getUsersFromCSV($csvFile) {
	global $orcidReportLoc;
	$handle = fopen("$orcidReportLoc/$csvFile","r");
	if($handle !== FALSE) {
	//if(($handle = fopen("$orcidReportLoc/$csvFile","r")) !== FALSE) {
		while(($data = fgetcsv($handle,1000,",")) !== FALSE) {
			$users[] = $data;
		}
		//first row is just headings, so shift off array
		array_shift($users);
		fclose($handle);
		return $users;
	} else {
		//if CSV is unreadable, return NULL
		return NULL;
	}
}

//pulls number of users in report from file name
function getReportCount($report) {
	$sIndex = stripos($report,"x") + 1;
	$eIndex = stripos($report,".");
	$userCount = intval(substr($report,$sIndex,($eIndex-$sIndex)));
	return $userCount;
}

//attempts to initiate report download if valid report name is posted
function testArgs(){
    global $orcidReportLoc;
    global $orcidReportDirArray;
    
    if (count($_POST) == 0) return;
    
    $reportName = (isset($_POST["reportList"]) ? $_POST["reportList"] : "");
    if ($reportName != "" && in_array($reportName,$orcidReportDirArray)) {
    	//open and read selected report
    	$inputReport = fopen("$orcidReportLoc/$reportName", "r");
    	if($inputReport !== false) {
    		header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename=' . getDownloadName($reportName));
	
			//create a file pointer connected to the output stream and write all lines of report
			$output = fopen('php://output', 'w');
			while ($line = fgets($inputReport, 5000)) {
				fwrite($output, $line);
			}
			fwrite($output,"\r\n");
			fclose($output);
			exit();
    	}
    	else {
    		echo "\nERROR: Could not open selected report for download.\n";
    		return;
    	}
    	fclose($inputReport);
    }
    else {
    	echo "\nERROR: Invalid report name provided for download.\n";
    	return;
    }
}
?>

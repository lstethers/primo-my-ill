<?php
// Original source: https://github.com/alliance-pcsg/primo-explore-my-ill
// Wesleyan source: https://github.com/lstethers/primo-my-ill (heavily modified from original)

// helpful troubleshooting lines to uncomment below
//error_log($output, 3, "/home/librdev/applications/primo-my-ill/testout1");
//echo $output;
//var_dump($output);

/*******   Variables to edit *************/

/* the whitelist array only allows requests from the included domains  
/* should include localhost if you're using the local dev environment, and your primo domain*/
$whitelist=array("localhost", "onesearch.wesleyan.edu","ctw-wu.primo.exlibrisgroup.com");

/* ILLiad web platform key. For more info, check here: https://prometheus.atlas-sys.com/display/illiad/The+ILLiad+Web+Platform+API   */
/* key should look something like this: */
$key="XXX";    /* enter key here  */
$illiadDomain="XXX"; /* e.g. illiad.myinst.edu */

/******** End variables to edit ******************/

// Make sure the request is coming from a domain we trust; if not, exit
$domain=parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
if (!in_array($domain, $whitelist)){exit();}

$data=array();
$data["referer"]=$domain;

// Wesleyan - userid is actually the WesID, passed from OneSearch custom.js
// helpful for testing force to a specific user:  $userid='abc123';
$userid=strtolower($_GET["user"]);
$user_req="https://$illiadDomain/ILLiadWebPlatform/Users/ExternalUserId/$userid";

// Wesleyan - look up the illiad username based upon WesID provided from Alma
$ch = curl_init();
// set url
curl_setopt($ch, CURLOPT_URL, $user_req);
curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/xml","ApiKey: $key"));
//return the transfer as a string
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
// $user_output contains the output string
$user_output = curl_exec($ch);
// close curl resource to free up system resources
curl_close($ch);

$user_xml=simplexml_load_string($user_output);
$username=$user_xml->UserName;

// if we got a username, continue with getting the user's transactions
if (!empty($username)) {

	$trans_req="https://$illiadDomain/ILLiadWebPlatform/Transaction/UserRequests/$username";
	$ch = curl_init();
	// set url
	curl_setopt($ch, CURLOPT_URL, $trans_req);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/xml","ApiKey: $key"));
	//return the transfer as a string
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

	// $output contains the output string
	$output = curl_exec($ch);
	// close curl resource to free up system resources
	curl_close($ch);

	$xml=simplexml_load_string($output);

// set transaction counters
	$req_c=1;
	$lon_c=1;
	$art_c=1;

// For each transaction
	foreach ($xml as $trans){
  		$status=$trans->TransactionStatus;
  		$id=$trans->TransactionNumber;
// look at the transaction status to decide whether to return it to OneSearch
// we only return transactions that have a status indicating they are currently active (not finished or cancelled)
  		switch ($status){
// articles
		case "Delivered to Web":

		$cat="Articles";
		$d=$trans->TransactionDate;
		$expiredate=extendDate($d);
		$jtitle=$trans->PhotoJournalTitle;
		$jvolume=$trans->PhotoJournalVolume;
		$jissue=$trans->PhotoJournalIssue;
		$year=$trans->PhotoJournalYear;
		$author=$trans->PhotoArticleAuthor;
		$title=$trans->PhotoArticleTitle[0];
		$url="https://$illiadDomain/illiad/illiad.dll?Action=10&Form=75&Value=$id";

		$data[$cat][$art_c]["id"]="$id";
		$data[$cat][$art_c]["jtitle"]="$jtitle";
		$data[$cat][$art_c]["jvolume"]="$jvolume";
		$data[$cat][$art_c]["jissue"]="$jissue";
		$data[$cat][$art_c]["year"]="$year";
		$data[$cat][$art_c]["author"]="$author";
		$data[$cat][$art_c]["title"]="$title";
		$data[$cat][$art_c]["url"]="$url";
		$data[$cat][$art_c]["count"]="$art_c";
		$data[$cat][$art_c]["expires"]="$expiredate";

		$art_c++;

		break;
// loans
		case "Checked Out to Customer":
		case "Customer Notified via E-mail":

		$cat="Loans";

		$requestType=$trans->RequestType;
		$loanAuthor=$trans->LoanAuthor;
		$loanTitle=$trans->LoanTitle;
		$articleTitle=$trans->PhotoArticleTitle;
		$articleAuthor=$trans->PhotoArticleAuthor;
		$documentType=$trans->DocumentType;
		$url="https://$illiadDomain/illiad/illiad.dll?Action=10&Form=72&Value=$id";

		$data[$cat][$lon_c]["id"]="$id";
		$data[$cat][$lon_c]["type"]="$requestType";
		$data[$cat][$lon_c]["docType"]="$documentType";
		$data[$cat][$lon_c]["count"]="$lon_c";
		$data[$cat][$lon_c]["url"]="$url";

		if ($requestType=="Article"){
		  $data[$cat][$lon_c]["author"]="$articleAuthor";
		  $data[$cat][$lon_c]["title"]="$articleTitle";
		}
		if ($requestType=="Loan"){
		  $data[$cat][$lon_c]["author"]="$loanAuthor";
		  $data[$cat][$lon_c]["title"]="$loanTitle";
		  $dued=$trans->DueDate;
		  $dueDate=convertDate($dued);
		  $data[$cat][$lon_c]["duedate"]="$dueDate";
		}
		$lon_c++;

		break;

// don't show old, completed transactions
		case "Request Finished":
		case "Cancelled by ILL Staff":
		break;

// There are so many different status possiblities for a request let's just make it the default processing state
		default:

		$cat="Requests";

		$requestType=$trans->RequestType;
		$loanAuthor=$trans->LoanAuthor;
		$loanTitle=$trans->LoanTitle;
		$articleTitle=$trans->PhotoArticleTitle;
		$articleAuthor=$trans->PhotoArticleAuthor;
		$documentType=$trans->DocumentType;
		$url="https://$illiadDomain/illiad/illiad.dll?Action=10&Form=72&Value=$id";

		$data[$cat][$req_c]["id"]="$id";
		$data[$cat][$req_c]["type"]="$requestType";
		$data[$cat][$req_c]["docType"]="$documentType";
		$data[$cat][$req_c]["count"]="$req_c";
		$data[$cat][$req_c]["url"]="$url";

		if ($requestType=="Article"){
		  $data[$cat][$req_c]["author"]="$articleAuthor";
		  $data[$cat][$req_c]["title"]="$articleTitle";
		}
		if ($requestType=="Loan"){
		  $data[$cat][$req_c]["author"]="$loanAuthor";
		  $data[$cat][$req_c]["title"]="$loanTitle";
		}
		$req_c++;

		break;

	  } // end switch
	} // end foreach
} // end if

// send the data back to the calling application
echo json_encode($data);

function convertDate($orig){
// keep original due date for loans
  $pieces=explode("T", $orig);
  array_pop($pieces);
  $date=implode(" ", $pieces);

  $datetime = new DateTime($date);
  return $datetime->format('M jS, Y');


}

function extendDate($orig){
// extend date for articles because articles stay in user account 30 days before switching to Request Finished

  $pieces=explode("T", $orig);
  array_pop($pieces);
  $date=implode(" ", $pieces);

  $datetime = new DateTime($date);
  $datetime->modify('+30 day');
  return $datetime->format('M jS, Y');


}

?>

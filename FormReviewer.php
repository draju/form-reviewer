<?php
/**
 * REDCap External Module: Form Reviewer
 * 
 * Allows you to optionally embed these elements of a source form into a review form:
 * 1. An inline PDF of any data saved to the source form.
 * 2. Download links for any files uploaded to the source form. 
 * 
 * @author Vishnu Raju, Albert Einstein College of Medicine
 */
namespace Einstein\FormReviewer;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

use REDCap;

class FormReviewer extends \ExternalModules\AbstractExternalModule {

    //Project settings 
    private $api_user;
    private $settings;

    function __construct()
    {
        parent::__construct();
        if($this->getProjectId()){
          $this->settings = $this->framework->getSubSettings("review-mapping");
          //Note that api_user is not saved on configuration screen - it's detected, validated and saved programmatically
          $this->api_user = $this->getProjectSetting("api-user"); 
        }
    }

    function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        //Embed source form if the current instrument was configured for Form Reviewer w/ bottom placement
        if( ($index = $this->searchSettings($instrument,"reviewer-form-name")) >= 0 ){
            if($this->settings[$index]["pdf-placement"] === "bottom" || 
              (!$this->settings[$index]["pdf-placement"] && $this->settings[$index]["file-table-placement"] === "bottom")){
               $this->embedSourceForm($index, $project_id, $record, $instrument, $event_id, $repeat_instance);
            }
        }
    }

    function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        //Embed source form if the current instrument was configured for Form Reviewer w/ top placement
        if( ($index = $this->searchSettings($instrument,"reviewer-form-name")) >= 0 ){
            if($this->settings[$index]["pdf-placement"] === "top" || 
              (!$this->settings[$index]["pdf-placement"] && $this->settings[$index]["file-table-placement"] === "top")){
                $this->embedSourceForm($index, $project_id, $record, $instrument, $event_id, $repeat_instance);
            }
        }
    }

    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        //Embed source form if the current instrument was configured for Form Reviewer w/ bottom placement
        if( ($index = $this->searchSettings($instrument,"reviewer-form-name")) >= 0 ){
            if($this->settings[$index]["pdf-placement"] === "bottom" || 
              (!$this->settings[$index]["pdf-placement"] && $this->settings[$index]["file-table-placement"] === "bottom")){
                $this->embedSourceForm($index, $project_id, $record, $instrument, $event_id, $repeat_instance);
            }
        }
    }

    function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        //Embed source form if the current instrument was configured for Form Reviewer w/ top placement
        if( ($index = $this->searchSettings($instrument,"reviewer-form-name")) >= 0 ){
            if($this->settings[$index]["pdf-placement"] === "top" || 
              (!$this->settings[$index]["pdf-placement"] && $this->settings[$index]["file-table-placement"] === "top")){
                $this->embedSourceForm($index, $project_id, $record, $instrument, $event_id, $repeat_instance);
            }
        }    
    }

    /** 
    * Embed elements of source form into review form
    */
    private function embedSourceForm($index, $project_id, $record, $instrument, $event_id, $repeat_instance){

        //echo "<PRE>".print_r($this->settings)."</PRE>";
        echo "<link rel='stylesheet' href='".$this->getUrl("css/form_reviewer.css")."'>";
        
        //Get record number of the source form     
        $record_link = $this->getLinkFieldValue($index, $project_id, $record, $event_id, $repeat_instance, $this->settings[$index]["record-link"]);
        
        //If source project is longitudinal, get unique event name of the source form
        if($this->settings[$index]["longitudinal"] === "yes"){
            $event_link = $this->getLinkFieldValue($index, $project_id, $record, $event_id, $repeat_instance, $this->settings[$index]["event-link"]);            
        }
        
        //Add download links for files uploaded to the source form
        if($this->settings[$index]["include-files"] == "1"){
            $this->addAttachments($index, $project_id, $instrument, $record_link, $event_link);
        }

        //Embed an inline PDF of the source form
        if($this->settings[$index]["embed-pdf"] == "1"){
            $this->embedPDF($index, $project_id, $instrument, $record_link, $event_link);
        }
        
    }
    
    function validateSettings($settings){

        //TODO: Detect if source form is repeating and block user from saving such a configuration
        //REDCap API for exporting PDFs doesn't support repeat instance parameter and seems to always returns the first instance
        
        //Check that each source form name exists for the specified project
        for ($i=0, $len=count($settings["source-form-name"]); $i<$len; $i++) {
            $sql_form_name = "SELECT COUNT(*) ".
                             "FROM redcap_metadata ".
                             "WHERE project_id='".db_escape($settings["source-project"][$i])."' ".
                             "AND form_name='".db_escape($settings["source-form-name"][$i])."'";
            $form_count = db_result(db_query($sql_form_name),0);
            if(!$form_count){
                $setting_num = $i + 1;
                return "Setting #$setting_num: The source form '".REDCap::escapeHtml($settings["source-form-name"][$i]).
                "' does not exist in project ID#".REDCap::escapeHtml($settings["source-project"][$i]);
            }
        }       

        //If specifying a subset of file upload fields, make sure that each one is defined in the source project
        for ($i=0, $len_settings=count($settings["include-files-portion"]); $i<$len_settings; $i++) {
            if($settings["include-files-portion"][$i] === "subset"){
                for ($j=0, $len_file_list=count($settings["file-upload-fields"][$i]); $j<$len_file_list; $j++) {
                    $sql_field_name = "SELECT COUNT(*) ".
                                      "FROM redcap_metadata ".
                                      "WHERE project_id='".db_escape($settings["source-project"][$i])."' ".
                                      "AND element_type = 'file' ".
                                      "AND field_name='".db_escape($settings["file-upload-fields"][$i][$j])."'";
                    $field_name_count = db_result(db_query($sql_field_name),0);
                    if(!$field_name_count){
                        $setting_num = $i + 1;
                        return "Setting #$setting_num: The field name '".REDCap::escapeHtml($settings["file-upload-fields"][$i][$j])."' ".
                        "either does not exist or is not a file upload field in project ID#".REDCap::escapeHtml($settings["source-project"][$i]);
                    }
                }
            }
        } 

        //To save settings, the current user must have API Export tokens for ALL configured projects
        //Update the saved api-user on each configuration save just in case a new user is taking over the project
        //This module does not save the token anywhere, it just looks it up when needed based on the saved username.
        $found_all_tokens = true;
        for ($i=0, $len=count($settings["source-project"]); $i<$len; $i++) {
            //Check for api token
            $sql_api_token = "SELECT api_token ".
                             "FROM redcap_user_rights ".
                             "WHERE username='".USERID."' ".
                             "AND project_id='".db_escape($settings["source-project"][$i])."' ".
                             "AND api_export='1'";
            $api_token = db_result(db_query($sql_api_token),0);
            if(!$api_token){
                $found_all_tokens = false;
                $setting_num = $i + 1;
                return "Setting #$setting_num: The user '".USERID."' ".
                "must have an API export token for the source project ID#".REDCap::escapeHtml($settings["source-project"][$i]);
            }
        }
        if($found_all_tokens){
            $this->setProjectSetting("api-user",USERID);
        }

        return parent::validateSettings($settings);
        
    }

    /** 
    * Returns index of setting if found.
    * Note that a value of zero is a valid index.
    */
    private function searchSettings($value,$key){
      foreach ($this->settings as $k => $val) {
        if ($val[$key] == $value) {
            return $k;
        }
      }
      return null;
    }

    /**
     * Gets value of a field on review form that links to source project - Ex: record number 
     */
    private function getLinkFieldValue($index, $project_id, $record, $event_id, $repeat_instance, $link_field_name){
        //Note that 1 is passed in for first instance even though the DB stores null
        $repeat_instance_clause = ($repeat_instance == 1 ? "instance is NULL" : "instance=$repeat_instance");
        
        $sql_link_field_value = "SELECT value ".
                                "FROM redcap_data ".
                                "WHERE project_id=$project_id ".
                                "AND record='{$record}' AND event_id=$event_id ".
                                "AND $repeat_instance_clause AND field_name='".db_escape($link_field_name)."'";
        $link_field_value = db_result(db_query($sql_link_field_value),0);
        
        return $link_field_value;
    }

    /**
    * Add files attached to source form as download links on review form
    */
    private function addAttachments($index, $project_id, $instrument, $record_link, $event_link) {

        if(!$this->settings["api-token"]){
            $sql_api_token = "SELECT api_token ".
                             "FROM redcap_user_rights ".
                             "WHERE username='".db_escape($this->api_user)."' ".
                             "AND project_id='".db_escape($this->settings[$index]["source-project"])."' ".
                             "AND api_export='1'";
            $this->settings[$index]["api-token"] = db_result(db_query($sql_api_token),0);
        }

        //Only include the specified file upload fields
        if($this->settings[$index]["include-files-portion"] === "subset"){
            //Make a copy of the upload fields array so we can escape each one individually
            $file_upload_arr = $this->settings[$index]["file-upload-fields"];
            for($i=0; $i<count($file_upload_arr); $i++){
                $file_upload_arr[$i] = db_escape($file_upload_arr[$i]);
            }
            $field_name_clause = implode("','",$file_upload_arr);            
            $field_name_clause = "'".$field_name_clause."'";
        }
        else { //Alternatively, add all file upload fields defined on the source form
            $field_name_clause = "SELECT field_name ".
                                 "FROM redcap_metadata ".
                                 "WHERE element_type='file' ".
                                 "AND form_name='".db_escape($this->settings[$index]["source-form-name"])."' ".
                                 "AND project_id='".db_escape($this->settings[$index]["source-project"])."'";
        }

        //Initialize html list/table of file attachments so we can add them in the loop below            
        $attach_list = "<div><ul>";
        $attach_tbl = "<table class='form-reviewer'>";
        $attach_tbl .= "<tr><th>File Attachment</th><th>Link</th></tr>";
        
        //Get the list of file upload fields to potentially create download links for
        $sql_docs = "SELECT DISTINCT field_name ".
                    "FROM redcap_data ".
                    "WHERE record='".db_escape($record_link)."' ".
                    "AND project_id='".db_escape($this->settings[$index]["source-project"])."' ".
                    "AND field_name IN ($field_name_clause)";

        $q = db_query($sql_docs);
        if(db_num_rows($q) === 0){
            $attach_list .= "<li>File upload field(s) not found</li>";
            $attach_tbl .= "<tr><td>none</td><td>none</td></tr>";
        }

        $retrieved_file = false;
        while ($row = db_fetch_array($q)){
            //Only call API if the data shows that a file was uploaded to that record & event
            $has_upload = false;
            $file_upload_data = REDCap::getData($this->settings[$index]["source-project"],'array',$record_link,$row["field_name"],$event_link);
            foreach($file_upload_data[$record_link] as $event_id=>$data){
                foreach($data as $field_name => $value){
                    if($field_name === $row["field_name"] && $value){
                        $has_upload = true;
                        break;
                    }
                }
            }
            if($has_upload){
                $data = array(
                    'token' => $this->settings[$index]["api-token"],
                    'content' => 'file',
                    'action' => 'export',
                    'record' => $record_link,
                    'field' => $row["field_name"],
                    'event' => $event_link,
                    'returnFormat' => 'json'
                );
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, APP_PATH_WEBROOT_FULL . "api" . DIRECTORY_SEPARATOR);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);           
                curl_setopt($ch, CURLOPT_VERBOSE, 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_AUTOREFERER, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
                //We need to read the HTTP headers to get the original file name to display as a link
                //This function is called by curl for each header received
                //See https://stackoverflow.com/questions/9183178/can-php-curl-retrieve-response-headers-and-body-in-a-single-request/25118032#25118032
                $form_reviewer_headers = array();
                curl_setopt($ch, CURLOPT_HEADERFUNCTION,
                    function($curl, $header) use (&$form_reviewer_headers)
                    {
                        $len = strlen($header);

                        $header = explode(':', $header, 2);
                        if (count($header) < 2) // ignore invalid headers
                            return $len;

                        $form_reviewer_headers[strtolower(trim($header[0]))][] = trim($header[1]);

                        return $len;
                    }
                );

                $output = curl_exec($ch);
                //echo "<PRE>form_reviewer_headers=".print_r($form_reviewer_headers)."</PRE>";
                $output_arr = json_decode($output,true);

                //Log any API errors and also display them to the screen in a table
                if($output_arr["error"]){
                    $error_summary["API Error Msg"] = $output_arr["error"];
                    $error_summary["API User"] = $this->api_user;
                    $error_summary["Project ID"] = REDCap::escapeHtml($this->settings[$index]["source-project"]);
                    $error_summary["File Upload Field"] = $data["field"];
                    $error_summary["Record"] = REDCap::escapeHtml($record_link);
                    $error_summary["Unique Event Name"] = REDCap::escapeHtml($event_link);
                    $this->displayErrorSummary($error_summary);
                    REDCap::logEvent(db_escape($this->getModuleName()), json_encode($error_summary, JSON_PRETTY_PRINT));
                }
                else {
                    //At least one file was retrieved by this set of API calls
                    $retrieved_file = true;

                    //Get the original file name from the HTTP header
                    if($form_reviewer_headers["content-type"]){
                        $attachment_filename = $this->getFileNameFromContentType($form_reviewer_headers["content-type"][0]);
                    }
                    else{
                        $attachment_filename = 'file';
                    }

                    //Save file to the REDCap temp directory with timestamp prefix so cron will delete it automatically 
                    $attachment_filename_ts = date('YmdHis')."_fr_"."pid".$project_id."_".$record_link."_".$event_link."_".$attachment_filename;
                    file_put_contents(APP_PATH_TEMP.$attachment_filename_ts, $output);

                    //Get field labels for display 
                    $sql_field_label = "SELECT element_label ".
                                       "FROM redcap_metadata ".
                                       "WHERE project_id='".db_escape($this->settings[$index]["source-project"])."' ".
                                       "AND field_name='".$row["field_name"]."'";
                    $field_label = db_result(db_query($sql_field_label),0);
 
                    //Create links
                    $attach_tbl .= "<tr><td>$field_label</td>";
                    $attach_tbl .= "<td><a href='".APP_PATH_WEBROOT_FULL."temp".DIRECTORY_SEPARATOR.$attachment_filename_ts."' download>$attachment_filename</a></td></tr>";
                    $attach_list .= "<li>$field_label : ";
                    $attach_list .= "<a href='".APP_PATH_WEBROOT_FULL."temp".DIRECTORY_SEPARATOR.$attachment_filename_ts."' download>$attachment_filename</a></li>";    
                }  
                curl_close($ch);
            } //end if has_upload    
        } //end while loop
        $attach_list .= "</ul></div>";
        $attach_tbl .= "</table>";

        //Only display links if at least one upload was retrieved without error
        if($retrieved_file){
            if($this->settings[$index]["files-formatting"] === "table"){
                echo "<br>".$attach_tbl;
                return;
            }
            elseif($this->settings[$index]["files-formatting"] === "label"){
                if($this->isSurveyPage()){
                    $attach_selector = "tr#".$this->settings[$index]["field-label-insert"]."-tr td.labelrc:nth-of-type(2)";
                }
                else {
                    $attach_selector = "tr#".$this->settings[$index]["field-label-insert"]."-tr td.labelrc";
                }
            ?>
                <script type="text/javascript">
                $(document).ready(function(){
                    $("<?php echo $attach_selector; ?>").append("<?php
                    echo $attach_list;
                    ?>
                    ");
                });
                </script>               
            <?php
            }  
        } // end if retrieved_file
    }

    /** 
     * Embed inline PDF of source form in the review form 
     */
    private function embedPDF($index, $project_id, $instrument, $record_link, $event_link) {

        if(!$this->settings[$index]["api-token"]){
            $sql_api_token = "SELECT api_token ".
                             "FROM redcap_user_rights ".
                             "WHERE username='".db_escape($this->api_user)."' ".
                             "AND project_id='".db_escape($this->settings[$index]["source-project"])."' ".
                             "AND api_export='1'";
            $this->settings[$index]["api-token"] = db_result(db_query($sql_api_token),0);
        }

        $data = array(
            'token' => $this->settings[$index]["api-token"],
            'content' => 'pdf',
            'record' => $record_link,
            'instrument' => $this->settings[$index]["source-form-name"],
            'event' => $event_link,
            'returnFormat' => 'json'
        );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, APP_PATH_WEBROOT_FULL . "api" . DIRECTORY_SEPARATOR);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        $output = curl_exec($ch);
        $output_arr = json_decode($output,true);

        //Log any API errors and also display to the screen
        if($output_arr["error"]){
            $error_summary["API Error Msg"] = $output_arr["error"];
            $error_summary["API User"] = $this->api_user;
            $error_summary["Project ID"] = REDCap::escapeHtml($this->settings[$index]["source-project"]);
            $error_summary["Instrument"] = REDCap::escapeHtml($this->settings[$index]["source-form-name"]);
            $error_summary["Record"] = REDCap::escapeHtml($record_link);
            $error_summary["Unique Event Name"] = REDCap::escapeHtml($event_link);
            $this->displayErrorSummary($error_summary);
            REDCap::logEvent(db_escape($this->getModuleName()), json_encode($error_summary, JSON_PRETTY_PRINT));
        }
        else {
            $source_form_filename = date('YmdHis')."_fr_pid".$project_id."_".$record_link."_".$event_link."_".$instrument.".pdf";
            file_put_contents(APP_PATH_TEMP.$source_form_filename, $output);
            echo "<iframe id='form-reviewer-src-form' src='".APP_PATH_WEBROOT_FULL."temp".DIRECTORY_SEPARATOR.$source_form_filename."' width=\"800px\" style=\"height:480px;\"></iframe>";
        }
        curl_close($ch);    
    }

    /**
    * Helper function to retrieve the file name from the HTTP header's content-type string
    */
    private function getFileNameFromContentType( $content_type ) {
        $name_part = strstr($content_type,'name="');
        if(strpos($name_part,'"',6) !== false){
            $name = substr($name_part,6,strpos($name_part,'"',6)-6);
            return $name;
        }
        return null;
    }
    
    /**
    * Helper function to display errors resulting from failed API calls
    */
    private function displayErrorSummary($error_summary){
        
        $error_tbl = "<br>";
        $error_tbl .= "<table class='form-reviewer'>";
        $error_tbl .= "<tr><th>Form Reviewer Module:</th><th>Error Detected!</th></tr>";
        foreach($error_summary as $key => $value)
        {
            $error_tbl .= "<tr><td>".$key."</td><td>".$value."</td></tr>";
        }
        $error_tbl .= "</table>";
        echo $error_tbl;
    }
}
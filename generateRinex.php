
<?php        
                require_once '../../../classes/CigalaRinex.php';
                ini_set("max_execution_time", 15000);  
                ini_set("output_buffering", 128);
                
                $obj=new CigalaRinex();
                $obj->ftp_connect();
                $obj->db_connect();
                
               //echo "<br>Please wait..don't refresh the page..<br>";
                $start=$obj->start_counter();
                
                
                $obj->setName($_REQUEST['station']);
                $obj->setYear($_REQUEST['year']);
                $obj->setDay($_REQUEST['day_year']);
                
                if(isset($_REQUEST['ftp_root'])){ //change the ftp root if necessary                   
                    $obj->ftp_changeRootTo($_REQUEST['ftp_root']);
                }
                
                if(isset($_REQUEST['snr'])){
                    $snr=true;
                }
                else{
                    $snr=false;
                }
                $version='3.01'
                                                                                                           
               /*  $interval=$_REQUEST['interval'];  //set the desired interval                               
                if($_REQUEST['version']=='3.01') {
                    $version="-R3"; // set the version
                } 
                else if($_REQUEST['version']=='2.11c'){
                    $version="2.11c"; // set the version 2.11c (only civil data)
                }
                else {
                    $version=null; //default - 2.11
                } */
                               
               // print_r($_REQUEST);
                
                $i=0;
                $lenght=count($_REQUEST['f']);
                $obj->setReceiverParams();
                //print_r($obj);
                echo "Converting: <br>";
                
                flush();
                foreach ($_REQUEST['f'] as $file){
                    $i++;                    
                    $obj->setFile($file);                                                               
                    $obj->getBinaryFile();                
                    $obj->extractRinexLinux();
                    $obj->sbfConversion($version, $interval, $snr, true); 
                    $merge[]=$obj->getFile();   
                    echo str_pad("File $i of $lenght.<br>", 512, ' ', STR_PAD_RIGHT);                    
                    flush();                    
                }                
                
                if($version==="-R3"){
                    $message=$obj->mergeRinex($merge);                   
                }
                else if($version==="2.11c"){
                    $message=$obj->mergeRinexTeqc($merge, TRUE, "g"); 
                }
                else {
                    $message=$obj->mergeRinexTeqc($merge);                                                  
                }
                
                $time_rinex=$obj->stop_counter($start);
                
                echo "<br>";
                if($time_rinex) $obj->displayEllapsedTimeAndMemory($time_rinex, "Rinex");        
                echo "<br>";
                
                                                                    
                //check the file
                if( !filesize($obj->rinex_path.$obj->getFile() ) ){                    
                    echo "<br><b>Error: all data from this day are corrupt. Try another day or station, please.</b><br>";
                    
                }
                // show the link to download if ok
                else{
                    echo $message;
                    echo "<a href='{$obj->rinex_path}{$obj->getFile()}' target='_blank' 
                        onclick='document.execCommand(\"SaveAs\")'> 
                        Save Rinex File </a>";
                }
                              
                $obj->ftp_disconnect();
                $obj->db_disconnect();
            
        ?>
    </body>
</html>

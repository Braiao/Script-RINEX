<?php

/**
 * Script para a criacao automatica de arquivos RINEX
 * Capaz de criar, na mesma chamada:
 * -  um arquivo RINEX 2.11 e compacta com gz  e/ou
 * -  um pacote de arquivo RINEX 3 horarios, todos compactados com HATANAKA.Z
 * 
 * Utilizacao com parametros nominais:
 *  php -f serv_createRinex-both.php station=NNNN \
 *         year=yyyy begin=YYDDD end=YYDDD  \
 *         [interval=15] [force_mode=[211|3] [ftp_root=Path/to/ftp]
 */
require_once 'CigalaRinex.php';
//ini_set("max_execution_time", 15000);      





$obj = new CigalaRinex();
$obj->ftp_connect();
$obj->db_connect();

//get command line params
for ($i = 1; $i < $argc; $i++) { //nome do arquivo eh argv[0]; argc contem a quantidade de parametros
    parse_str($argv[$i]);
}

if (!isset($interval)){ //interval for rinex 2
    $interval=15;
}

//check required params
if (isset($station) && isset($begin) && isset($end) && isset($year)) {
    $obj->setName($station);
    $obj->setYear($year);
    echo "\nStation: $station \n" .
    "Interval v2: $interval s\n".
    "Year: $year \n" .
    "Initial day: $begin \n" .
    "Final day: $end \n";
} else {
    echo "Error! Params. station, year, begin and end are required. \n";
    echo "Usage: php serv_createRinex-both.php station=NNNN year=yyyy begin=YYDDD end=YYDDD [force_mode=[211|3] [ftp_root=Path/to/ftp] \n";
    exit(0);
}

if (!isset($force_mode)) {
    $force_mode = "both";
}


//params 
$obj->setReceiverParams();
for (; $begin <= $end; $begin++) {
  
    $obj->setDay($begin); //ex: 12001 - first day of year 2012

    if (isset($ftp_root)) {  //Ex: $obj->ftp_changeRootTo('Backup-NAS');  
        $obj->ftp_changeRootTo($ftp_root);
    }  

    $list = $obj->listNewBinaryFiles();
    if(!is_array($list)){
        echo "empty list..\n";
        continue;        
    }
    $lenght = count($list);    
    

    echo "Total file(s): $lenght\n";
    $i = 1;
    $download=false;

    echo "\nPlease wait...converting\n";
    if ($force_mode == "211" || $force_mode == "both") {
        $download=true;
        if ($force_mode == "211") {
            $flag_delete = true;
        } else {
            $flag_delete = false;
        }

        echo "Rinex 2.11:\n";
        foreach ($list as $file) {
            $obj->setFile($file);
            $obj->getBinaryFile();
            $obj->extractRinexLinux();

            //rinex 2.11           
            $obj->sbfConversion(null, $interval, false, $flag_delete);
            $merge211[] = $obj->getFile();

            echo ("-Rinex 2.11: File $i of $lenght - $file \n");
            $i++;
        }

        
        $message = $obj->mergeRinexTeqc($merge211);
        $file211 = $obj->getFile();
        if (!filesize($obj->full_rinex_path . $file211)) {
            echo "\nError: null rinex 2.11. \n";
        } else {
            echo $message;
            echo "\nSuccesfully converted 2.11- $file211\n";
        }//else
    }


    $i=1;
    //rinex 3
    if ($force_mode == "3" || $force_mode == "both") {
        echo "Rinex 3:\n";
        foreach ($list as $file) {
            echo ("- Rinex 3: File $i of $lenght - $file \n");
            
            $obj->setFile($file);
            
            if(!$download){     //ainda nao baixou bruto
                $obj->getBinaryFile();
                $obj->extractRinexLinux(); //extract
            }
            else{
                $obj->extractRinexLinux(true); //ja baixou bruto, seta o nome apenas
            }
           
            //sbf conversion - hourly files 1s
            $message = $obj->sbfConversion3Service(
                                              true //delete files after conversion
                                                );              
            
            $merge3[] = $obj->getFile();

            
            $i++;
        }
        $obj->zipRinexs($merge3);
        
        
        
    }




    //check the files
    // show the link to download if ok
}//end for


$obj->ftp_disconnect();
$obj->db_disconnect();


//ini_restore('max_execution_time');
?>

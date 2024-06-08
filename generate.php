<?php
require_once 'CigalaRinex.php';
ini_set("max_execution_time", 15000);  
ini_set("output_buffering", 128);

/* $base_dir = __DIR__;
$tmp_dir = $base_dir . '/tmp/'; */

$obj = new CigalaRinex();
$ftp_host = "200.145.185.149";
$ftp_user = "cigala_ftp";
$ftp_password = "B0mb31r1nh0";

$conn_id = ftp_connect($ftp_host);
if(ftp_login($conn_id, $ftp_user, $ftp_password))
{
    echo "Conectado ao FTP";

    ftp_pasv($conn_id, true);

    $obj->setFtp_conn($conn_id);
}



//$obj->ftp_connect();
//$obj->db_connect();

$start = $obj->start_counter();

// Defina as estações, ano e dia fixos
$stations = ['STSH', 'STCB']; // Adicione suas estações específicas aqui
$year = date("Y"); // Defina o ano
$array_data = getdate();

//A função captura o dia do ano contando a partir de 0
$only_day = "$array_data[yday]";
//corrigindo isso
$only_day++;

$day_year = date("y").$only_day; // Defina o dia do ano

$doisdig_ano = date("y");
//$only_day = $array_data[yday];


$current_hour = $array_data['hours'];
$current_minute = $array_data['minutes'];

$hour_letter = chr(ord('a') + $current_hour);


$minute = (int)floor($current_minute / 15) * 15;
$minute_formatted = str_pad($minute, 2, '0', STR_PAD_LEFT);



foreach ($stations as $station) {

    $local_folder = '/RINEX3' . '/' . $station . '/' . $year;

    if(!is_dir($local_folder))
    {
        $command_folder = "mkdir " . $local_folder;
        shell_exec($command_folder);
    }

    if(!is_dir($local_folder . '/' . $day_year))
    {
        $command_folder = "mkdir " . $local_folder . '/' . $day_year;
        shell_exec($command_folder);
    }

    $local_folder = '/RINEX3' . '/' . $station . '/' . $year . '/' . $day_year . '/';



    $obj->setName($station);
    $obj->setYear($year);
    $obj->setDay($day_year);

    $snr = true; 


    $interval = 30; 
    $version = "-R3"; // Defina a versão desejada ("-R3", "2.11c", ou null)

    $name = $station . $only_day . "$hour_letter" . $minute_formatted . '.' . $doisdig_ano . '_';  

    $file = $station . $only_day . "$hour_letter" . $minute_formatted . '.' . $doisdig_ano . '_.gz';
    //station+diadoano+horapeloalfabeto+minuto(00-15-30-45)+.doiultimosdigitosdoano+_.gz 
     
    $arquivo = gzopen("/script/Script-RINEX/Script-RINEX/tmp/" . $name , 'w');  // --Pasta que o arquivo temporário será criado
    echo "       ";
    echo $name;
    echo "       ";
    if($arquivo)
    {
        gzclose($arquivo);
        echo "Arquivo criado com sucesso";
    }
    else
    {
        echo "Erro ao criar o arquivo .gz";
    }
    
    //echo "chegou aqui;";

    //echo $name;
    //echo $file;
    
    $files = [$file];
    //$files = ['STSH137a00.24_.gz']; // Adicione seus arquivos aqui
    $lenght = count($files);
   // $obj->setReceiverParams();

  //  echo "Converting: <br>";

    flush();
    $merge = [];
    foreach ($files as $file) {
        $obj->setFile($file);    
        echo $file;                                                           
        $obj->getBinaryFile();                
        $obj->extractRinexLinux();
        $obj->sbfConversion($version, $interval, $snr, true); 
        $obj->setLetter_hour($hour_letter);
        $merge[] = $obj->getFile();   
        //echo str_pad("File $i of $lenght.<br>", 512, ' ', STR_PAD_RIGHT);                    
        flush();                    
    }              
    
    
    echo '       2-       '.$obj->getFile();

    $message = $obj->mergeRinex($merge);                   
    //echo $message;

    echo '       3-       '.$obj->getFile();

    $time_rinex = $obj->stop_counter($start);
    
    //echo "<br>";
    if ($time_rinex) $obj->displayEllapsedTimeAndMemory($time_rinex, "Rinex");        
    //echo "<br>";

    $out_name = substr($obj->getFile(), 0, 7) . $obj->getLetter_hour() . substr($obj->getFile(), 8);
    // Verificar o arquivo

    echo '       4-       '.$obj->getFile();
    if (!filesize($obj->rinex_path . ($obj->getFile()))) {                    
        echo "Error: all data from this day are corrupt. Try another day or station, please.";
    } else {
        // Mover o arquivo para a pasta local

        //echo '       '.$obj->getFile();
        $local_file = $local_folder . basename($out_name);
        
        //echo '       '.$local_file;
        if (rename($obj->rinex_path . $obj->getFile(), $local_file)) {
            echo "Arquivo baixado com sucesso: '$local_file'>$local_file";
        } else {
            echo "Erro ao mover o arquivo para a pasta local.";
        }
        echo $message;
    }
    $command = "rm -r /script/Script-RINEX/tmp/". $file;
    //shell_exec($command);
}


ftp_close($conn_id);


?>
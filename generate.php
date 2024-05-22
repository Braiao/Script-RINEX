<?php
require_once 'CigalaRinex.php';
ini_set("max_execution_time", 15000);  
ini_set("output_buffering", 128);

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
$day_year = date("y")."$array_data[yday]"; // Defina o dia do ano
$doisdig_ano = date("y");
//$only_day = $array_data[yday];

$counter = -15;

$letter = ord("a");
if($letter > ord("x"))
{
    $letter = ord("a");
    $day_year = $day_year + 1;  
}
if($counter == 45)
{
    $letter++;
}
else
{
    $counter = $counter + 15;
}

if($counter == 0)
{
    $counter = 00;
}






foreach ($stations as $station) {
    
    $local_folder = '/RINEX3' . '/' . $station;

    if(!is_dir($local_folder . '/' . $day_year))
    {
        $command_folder = "mkdir " . $local_folder . '/' . $day_year;
        shell_exec($command_folder);
    }

    $local_folder = '/RINEX3' . '/' . $station . '/' . $day_year;



    $obj->setName($station);
    $obj->setYear($year);
    $obj->setDay($day_year);
    $letter_hora = chr($letter);
   
    

    // Opcional: Defina o diretório raiz do FTP se necessário
    /* $ftp_root = '/';
    $obj->ftp_changeRootTo($ftp_root); */

    // Opcional: SNR
    $snr = true; // ou false dependendo da sua necessidade

    // Defina o intervalo desejado
    $interval = 30; // Intervalo em segundos
    $version = "-R3"; // Defina a versão desejada ("-R3", "2.11c", ou null)
    if($counter == 0)
    {
        $file = $station . "$array_data[yday]" . "$letter_hora" . "00" . '.' . $doisdig_ano . '_.gz';
        //station+diadoano+horapeloalfabeto+minuto(00-15-30-45)+.doiultimosdigitosdoano+_.gz 
        $name = $station . "$array_data[yday]" . "$letter_hora" . "00" . '.' . $doisdig_ano . '_';    
    }
    else
    {
        $file = $station . "$array_data[yday]" . "$letter_hora" . $counter . '.' . $doisdig_ano . '_.gz';
        //station+diadoano+horapeloalfabeto+minuto(00-15-30-45)+.doiultimosdigitosdoano+_.gz 
        $name = $station . "$array_data[yday]" . "$letter_hora" . $counter . '.' . $doisdig_ano . '_';   
    }
    
    $arquivo = gzopen("tmp/" . $name , 'w');
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
        $obj->getBinaryFile();                
        $obj->extractRinexLinux();
        $obj->sbfConversion($version, $interval, $snr, true); 
        $merge[] = $obj->getFile();   
        //echo str_pad("File $i of $lenght.<br>", 512, ' ', STR_PAD_RIGHT);                    
        flush();                    
    }                

    $message = $obj->mergeRinex($merge);                   

    $time_rinex = $obj->stop_counter($start);

    //echo "<br>";
    if ($time_rinex) $obj->displayEllapsedTimeAndMemory($time_rinex, "Rinex");        
    //echo "<br>";

    // Verificar o arquivo
    if (!filesize($obj->rinex_path . $obj->getFile())) {                    
        echo "Error: all data from this day are corrupt. Try another day or station, please.";
    } else {
        // Mover o arquivo para a pasta local
        $local_file = $local_folder . basename($obj->getFile());
        if (rename($obj->rinex_path . $obj->getFile(), $local_file)) {
            echo "Arquivo baixado com sucesso: '$local_file'>$local_file";
        } else {
            echo "Erro ao mover o arquivo para a pasta local.";
        }
        echo $message;
    }
    $command = "rm -r tmp/" . $file;
   //shell_exec($command);


    /* if (!filesize($obj->rinex_path . $obj->getFile())) {                    
        echo "Error: all data from this day are corrupt. Try another day or station, please.";
    } else {
        // Mover o arquivo para a pasta local
        $local_file = $local_folder . basename($obj->getFile());
        if (rename($obj->rinex_path . $obj->getFile(), $local_file)) {
            echo "Arquivo baixado com sucesso: '$local_file'>$local_file";
        } else {
            echo "Erro ao mover o arquivo para a pasta local.";
        }
        echo $message;
    }
    $command = "rm -r tmp/" . $name . ".txt";
    shell_exec($command); */
}


ftp_close($conn_id);
//$obj->ftp_disconnect();
//$obj->db_disconnect();


?>
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
$stations = ['STCB', 'STSH']; // Adicione suas estações específicas aqui
$year = 2024; // Defina o ano
$day_year = 24137; // Defina o dia do ano

// Defina a pasta onde deseja salvar os arquivos baixados
$local_folder = '/home/braia/RINEX3';

foreach ($stations as $station) {
    $obj->setName($station);
    $obj->setYear($year);
    $obj->setDay($day_year);

    // Opcional: Defina o diretório raiz do FTP se necessário
    /* $ftp_root = '/';
    $obj->ftp_changeRootTo($ftp_root); */

    // Opcional: SNR
    $snr = true; // ou false dependendo da sua necessidade

    // Defina o intervalo desejado
    $interval = 30; // Intervalo em segundos
    $version = "-R3"; // Defina a versão desejada ("-R3", "2.11c", ou null)

    $i = 0;
    $files = ['STSH137a00.24_.gz']; // Adicione seus arquivos aqui
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

    echo "<br>";
    if ($time_rinex) $obj->displayEllapsedTimeAndMemory($time_rinex, "Rinex");        
    echo "<br>";

    // Verificar o arquivo
    if (!filesize($obj->rinex_path . $obj->getFile())) {                    
        echo "<br><b>Error: all data from this day are corrupt. Try another day or station, please.</b><br>";
    } else {
        // Mover o arquivo para a pasta local
        $local_file = $local_folder . basename($obj->getFile());
        if (rename($obj->rinex_path . $obj->getFile(), $local_file)) {
            echo "Arquivo baixado com sucesso: <a href='$local_file'>$local_file</a><br>";
        } else {
            echo "Erro ao mover o arquivo para a pasta local.<br>";
        }
        echo $message;
    }
}


ftp_close($conn_id);
//$obj->ftp_disconnect();
//$obj->db_disconnect();


?>
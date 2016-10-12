<?php
/**
 * Usage:
 * File Name: csv.class.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-10-12 17:41:08
 **/
class CSV
{
    public $delimiter = ",";
    public $enclosure = "\"";
    public $linefeed  = "\r\n";
    public function __construct()
    {
    }
    public function sendCSVHeaders($filename = 'file.csv')
    {
        header("Content-type: text/csv");
        header("Content-Disposition: attachment; filename={$filename}");
        header("Pragma: no-cache");
        header("Expires: 0");
    }
    /**
     * Convert 2-dimensional array to CSV
     */
    public function arrayToCSV(array $array)
    {
        $csv = '';
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $csv .= $this->CSVRow($val);
            } else {
                $csv .= $this->CSVRow(array($key, $val));
            }
        }
        return $csv;
    }
    private function CSVRow($row)
    {
        $rowtext = "";
        $first = true;
        foreach ($row as $col) {
            if (!$first) {
                $rowtext .= $this->delimiter;
            }
            $col = utf8_decode($col);
            $col = str_replace('"', '""', $col);
            $rowtext .= $this->enclosure . "$col" . $this->enclosure;
            $first = false;
        }
        $rowtext .= $this->linefeed;
        return $rowtext;
    }
}

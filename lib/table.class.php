<?php
/**
 * Usage:
 * File Name: array2table.class.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-01-13 10:26:14
 **/

class Table {
	public function array2table($array, $title="", $highlight="")
	{
		$table = "<table>";
		$caption = "<caption>$title</caption>";
		$thead = "<thead><tr><th>";
		$tr = "<tr>";
		foreach($array as $k => $v)
		{
			$td = "";
			$th = implode("</th><th>", array_keys($v));
			foreach($v as $key => $value)
			{
				$td .= "<td>$value</td>";
			}
			$tr = $tr . $td . "</tr>";
		}
		$thead = $thead . $th . "</th></tr></thead>";
		$table = $table . $caption . $thead . $tr . "</table>";
		return $table;
	}
}

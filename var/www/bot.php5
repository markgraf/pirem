<?php
include ("config.php5");
header('Content-type: text/plain');

// bot.php5?date=...&time=...&location=...&title=...&content=

$MyBot = new Wikibot ( $username, $password, $wikiurl );
$datum = $_GET ["date"];
$zeit = $_GET ["time"];
$location = $_GET ["location"];
$title = $_GET ["title"];
$content = $_GET ["content"];
$setBotPassword = $_GET ["botpassword"];
$debug = "";

$mailbody = "Request: " . $_SERVER ["REQUEST_URI"] . "\nRequest-Data: " . print_r ( $_REQUEST, true ) . "\n_SERVER:" . print_r ( $_SERVER, true );
$debug .= $mailbody;
echo "Setze Termin um '" . $datum . "' um '" . $zeit . "' Uhr im Lokal '" . $location . "'\n";

$fehler = "";

if (stripos ( $title, "stammtisch" ) !== false && $botpassword == $setBotPassword ) {
	// Datums-Vorlage
	if (strlen ( $datum ) > 0 && $datum != $MyBot->get_page ( $vorlage ["datum"] )) {
		$edit = $MyBot->edit_page ( $vorlage ["datum"], $datum, "Datum aktualisiert." );
		if (! $edit)
			$fehler .= "Fehler: Konnte Wikiseite " . $vorlage ["datum"] . " nicht bearbeiten.\n";
	} else
		echo "Datum nicht gesetzt oder nicht geändert.\n";
	
	// Zeit-Vorlage
	if (strlen ( $zeit ) > 0 && $zeit != $MyBot->get_page ( $vorlage ["zeit"] )) {
		$edit = $MyBot->edit_page ( $vorlage ["zeit"], $zeit, "Zeit aktualisiert." );
		if (! $edit)
			$fehler .= "Fehler: Konnte Wikiseite " . $vorlage ["zeit"] . " nicht bearbeiten.\n";
	} else
		echo "Zeit nicht gesetzt oder nicht geändert.\n";
	
	// Location
	if (strlen ( $location ) > 0) {
		$old_location = $MyBot->get_page ( $vorlage ["ort"] );
		if ($location != $old_location) {
			$edit = $MyBot->edit_page ( $vorlage ["ort"], $location, "Ort aktualisiert." );
			$debug .= "Ort wurde von " . $old_location . " zu " . $location . " geändert.";
			if (! $edit)
				$fehler .= "Fehler: Konnte Wikiseite " . $vorlage ["ort"] . " nicht bearbeiten.\n";
		} else
			echo "Ort nicht geändert.\n";
	} else
		echo "Ort nicht gesetzt.\n";
} elseif (stripos ( $title, "stammtisch" ) === false) {
	$fehler .= "Fehler: Kein Stammtisch.\n";
} elseif ($botpassword != $setBotPassword) {	
	sleep(10);
	$fehler .= "Fehler: Falsches Passwort.\n";
}
write_log($debug, "Debug");

if (strlen ( $fehler) > 0 ) {
	echo $fehler;
	write_log($fehler, "Fehler");
}

function write_log($debug, $level) {
	$text = "\n\n\n" . time () . ": " . $debug;
	$handle = fopen ( $level.".txt", "a" );
	fwrite ( $handle, $text );
	fclose ( $handle );
	if ($_GET ["debug"]) {
		if (is_array ( $text ))
			echo "<pre>" . print_r ( $text, true ) . "</pre><br>";
		else
			echo "<pre>" . $text . "</pre><br>";
	} else
		mail($adminmail, $level." von FreiBot", $text );
}

?>
 
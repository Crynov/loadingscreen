<?php

// srcsrv
// a simple library for querying source engine servers
// by stgn, cvar response by steveuk

class BinHelp {
	function __construct($binstring) {
		$this->binstring = $binstring;
		$this->position = 0;
		$this->length = strlen($this->binstring);
	}
	
	function subbin($bytes) {
		if($this->position + $bytes <= $this->length) {
			$sub = substr($this->binstring, $this->position, $bytes);
			$this->position += $bytes;
			return $sub;
		} else { return false; }
	}
	
	function remaining() { return $this->length - $this->position; }
	
	function int8() { if($sub = $this->subbin(1)) return ord($sub); else return false; }	
	function int16() { if($sub = $this->subbin(2)) { $subsub = unpack("v", $sub); return $subsub[1]; } else return false; }
	function int32() { if($sub = $this->subbin(4)) { $subsub = unpack("V", $sub); return $subsub[1]; } else return false; }
	function float32() { if($sub = $this->subbin(4)) { $subsub = unpack("f", $sub); return $subsub[1]; } else return false; }
	function zstring() {
		if($zterm = strpos($this->binstring, "\0", $this->position) ) {
			$sub = $this->subbin($zterm - $this->position);
			$this->position++;
			return $sub;
		}
	}
}

class SrcSrv {
	function __construct($server) {
		$this->sock = stream_socket_client("udp://$server");
		stream_set_timeout($this->sock, 2);
		$this->Challenge();
	}
	
	function Challenge() {
		fwrite($this->sock, "\xff\xff\xff\xffU\xFF\xFF\xFF\xFF");
		$resp = fread($this->sock, 1500);
		
		if($resp && $resp[4] == "A") {
			$hb = new BinHelp($resp);
			$hb->subbin(5);
			$this->Challenge = $hb->subbin(4);
			return true;
		}
		return false;
	}
	
	function Info() {
		fwrite($this->sock, "\xff\xff\xff\xffTSource Engine Query\0");
		$resp = fread($this->sock, 1500);
	
		if($resp && $resp[4] == "I") {
			$bh = new BinHelp($resp);
			$bh->subbin(6);
			$retn["name"] = $bh->zstring();
			$retn["map"] = $bh->zstring();
			$retn["gamedir"] = $bh->zstring();
			$retn["desc"] = $bh->zstring();
			$retn["appid"] = $bh->int16();
			$retn["players"] = $bh->int8();
			$retn["max"] = $bh->int8();
			$retn["bots"] = $bh->int8();
			$retn["dedi"] = $bh->subbin(1);
			$retn["os"] = $bh->subbin(1);
			$retn["password"] = (bool)$bh->int8();
			$retn["vac"] = (bool)$bh->int8();
			$retn["version"] = $bh->zstring();

			if($edf = $bh->int8()) {
				if($edf & 0x80)
					$retn["port"] = $bh->int16();
				if($edf & 0x20)
					$retn["tags"] = $bh->zstring();
			}
		
			return $retn;
		}
	
		return false;
	}
	
	function Players() {
		fwrite($this->sock, "\xff\xff\xff\xffU" . $this->Challenge);
		$resp = fread($this->sock, 1500);
		
		if($resp && $resp[4] == "D") {
			$hb = new BinHelp($resp);
			$hb->subbin(6);
			$players = $hb->int8();
			while($hb->remaining() > 0) {
				$hb->int8();
				$retn[] = array("name" => $hb->zstring(), "score" => $hb->int32(), "time" => $hb->float32());
			}
			return $retn;
		}
		
		return false;
	}
	
	function Cvars()
	{
		fwrite( $this->sock, "\xff\xff\xff\xff\x56" . $this->Challenge );
		$responses = array();
		
		while( $part = fread( $this->sock, 2500 ) )
			$responses[] = $part;
		
		$retval = array();
		
		foreach( $responses as $k => $response )
		{
			if( !empty( $response ) && ( $k != 0 || ( $response[16] == "E" || $response[4] == "E" ) ) ) // got cavrs
			{
				$hb = new BinHelp( $response );
				
				if( $response[16] == "E" )
					$hb->subbin( 12 ); // additional header length for gmod
				
				// offset depending if it's the first packet or not.
				if( $k == 0 )
					$hb->subbin( 7 );
				else
					$hb->subbin( 12 );
						
				while( $hb->remaining() > 0 )
				{		
					$retval[] = array( "cvar" => $hb->zstring(), "value" => $hb->zstring() );
				}
			}
		}
		
		return $retval; // built array
	}
}

?>
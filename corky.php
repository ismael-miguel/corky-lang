<?php

final class Corky_Parser {
	static private $regex = '/:
	(?P<skip>:)? # skips the code if there is ::<rule>
	(?:
		(?P<var> # parses things like ^&-1 or ~0
			(?P<parent>\^)?
			(?P<type>[~&]) # ~ is a variable | & is a function
			(?P<identifier>\d+|-1)
		)
		|
		(?P<fn>[a-z][a-z_]*)
		(?:@
			(?P<arg>
				(?P<fn_arg>[a-z_]+) # function name
				|
				(?P<static>\d+) # integer
				|
				(?P<dynamic>\d+\.\d*|\d*.\d+) # floating-point
				| # https://stackoverflow.com/a/481294
				(?P<text>"([^\\"]|\\\\|\\")*") # double-quoted escaped string
			)
		)?
	)/Axis';
	
	private static function tokenize_var(array $pieces) {
		return array(
			'token' => 'var',
			'type' => $pieces['type'],
			'identifier' => $pieces['identifier'],
			'parent' => isset($pieces['parent']) && $pieces['parent']
		);
	}
	
	private static function tokenize_fn(array $pieces) {
		$token = array(
			'token' => $pieces['fn']
		);
		
		if(isset($pieces['arg']) && $pieces['arg'])
		{
			foreach(array('fn' => 'fn_arg', 'static', 'dynamic', 'text') as $type => $value)
			{
				if(is_numeric($type))
				{
					$type = $value;
				}
				
				if(isset($pieces[$value]) && $pieces[$value])
				{
					$token['arg'] = array(
						'type' => $type,
						'value' => $pieces[$value]
					);
					break;
				}
			}
		}
		
		return $token;
	}
	
	static function parse($string){
		$tokens = array();

		$line = 1;
		// make sure it's a string
		$code = $string . '';

		for($i = 0, $length = strlen($code); $i < $length; $i++)
		{
			$eof = $i + 1 >= $length;
			
			switch($code[$i])
			{
				case "\r":
					if(!$eof && $code[$i + 1] === "\n")
					{
						$i++;
					}
				case "\n":
					$line++;
					break;
				case ':':
					if($eof)
					{
						continue;
					}
					
					if(preg_match(self::$regex, $code, $pieces, 0, $i))
					{
						// reduces the code size, removing one token
						// this avoids accidental double-parsing, if there's a bug
						$code = substr($code, $i + strlen($pieces[0]));
						$i = -1; // leaving at 0 would skip a char
						$length = strlen($code);
						
						if(isset($pieces['skip']) && $pieces['skip'])
						{
							// lets leave before we do any more work
							break;
						}
						
						$token = isset($pieces['var']) && $pieces['var']
							? self::tokenize_var($pieces)
							: self::tokenize_fn($pieces);
						
						$token['line'] = $line;
						
						$tokens[] = $token;
					}
					break;
			}
		}
		
		return $tokens;
	}
}

final class Corky_Lexer {
	private $tokens = array();
	private $code = '';
	private $code_raw = '';
	
	private function parse(){
		$this->tokens = Corky_Parser::parse($this->code_raw);
	}
	
	function getTokens(){
		return $this->tokens;
	}
	
	function getCode(){
		if(!$this->code_raw || $this->code)
		{
			return $this->code;
		}
		
		$code = '';
		$last_line = 0;
		
		foreach($this->tokens as $token)
		{
			$code .= ($last_line && $token['line'] > $last_line ? "\r\n": '') . ':';
			
			$last_line = $token['line'];
			
			if($token['token'] === 'var')
			{
				$code .= ($token['parent'] ? '^' : '') . $token['type'] . $token['value'];
			}
			else
			{
				$code .= $token['token'] . (
					isset($token['arg'])
						? '@' . $token['arg']['value']
						: ''
				);
			}
		}
		
		return $this->code = $code;
	}
	
	function getRawCode(){
		return $this->code_raw;
	}

	function __construct($code){
		if($code)
		{
			$this->code_raw = $code;
			$this->parse();
		}
	}
}

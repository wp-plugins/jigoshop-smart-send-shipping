<?php

class smartSendUtils
{
	private $ssWSDL = 'http://developer.smartsend.com.au/service.asmx?wsdl';
	// const SSWSDL = 'http://smartsend-service.staging.emu.com.au/service.asmx?WSDL';

	// TTL in days for locations cache
	private $cacheTTL = 1;

	private $locationsCacheFile;

	// Soap connection object
	private $soapClient;

	// Auth details
	private $username = '';
	private $password = '';

	// List of locations
	private $locationList = array();

	// Coming from
	private $postcodeFrom;
	private $suburbFrom;
	private $stateFrom;

	// Going to
	private $postcodeTo;
	private $suburbTo;
	private $stateTo;

	// Arrays of params for quote
	// Each item contains:
	// 	Description: One of -
	// 		Carton, Satchel/Bag, Tube, Skid, Pallet, Crate, Flat Pack, Roll, Length, Tyre/Wheel, Furniture/Bedding, Envelope
	// 	Depth
	// 	Height
	// 	Length
	// 	Weight
	private $quoteItems;

	// Optional promo code
	private $promoCode;

	// Whether transport assurance required - default = 0
	private $transportAssurance = 0;

	// User type. Options are:
	// EBAY, PROMOTION, VIP
	private $userType = 'VIP';

	// Wether taillift required, if so what and where. Options are:
	// NONE, PICKUP, DELIVERY, BOTH
	private $tailLift = 'NONE';

	// Optional
	private $promotionalCode = '';
	private $onlineSellerId = '';

	private $receiptedDelivery = 'false';

	// Object containing the results of last quote
	private $lastQuoteResults;


	public function __construct( $username = NULL, $password = NULL )
	{
		if( is_null($username) && is_null($password) )
		{
			throw new Exception( 'Missing username and password.');
		}
		$this->username = $username;
		$this->password = $password;

		$this->soapClient = new SoapClient( $this->ssWSDL );
		$this->locationsCacheFile = dirname(__FILE__) . '/locations.data';
	}

	public function getQuote()
	{
		$required = array(
			'postcodeFrom', 'suburbFrom', 'stateFrom',
			'postcodeTo', 'suburbTo', 'stateTo',
			'userType', 'tailLift'
		);

		foreach( $required as $req )
		{
			if( is_null( $this->$req ) ) throw new Exception( "Cannot get quote without '$req' parameter" );
		}

		if( $this->userType == 'EBAY' && is_null($this->onlineSellerId ))
				throw new Exception( 'Online Seller ID required for Ebay user type.' );

		if( $this->userType == 'PROMOTION' && is_null($this->promotionalCode ))
				throw new Exception( "Promotional code required for user type 'PROMOTION'." );

		$quoteParams['request'] = array(
			'VIPUsername' => $this->username,
			'VIPPassword' => $this->password,
			'PostcodeFrom' => $this->postcodeFrom,
			'SuburbFrom' => $this->suburbFrom,
			'StateFrom' => $this->stateFrom,
			'PostcodeTo' => $this->postcodeTo,
			'SuburbTo' => $this->suburbTo,
			'StateTo' => $this->stateTo,
			'UserType' => $this->userType,
			'OnlineSellerID' => $this->onlineSellerId,
			'PromotionalCode' => $this->promotionalCode,			//string
			'ReceiptedDelivery' => 'false',		//boolean
			'TailLift' => $this->tailLift,				//string NONE, PICKUP, DELIVERY, BOTH
			'TransportAssurance' => 0,			//int
			'Items' => $this->quoteItems
		);

		$this->lastQuoteResults = $this->soapClient->obtainQuote( $quoteParams );

		return $this->lastQuoteResults;
	}

	/**
	 * @param array $fromDetails Array of 'from' address details: [ postcode, suburb, state, ]
	 */
	public function setFrom( $fromDetails )
	{
		list( $this->postcodeFrom, $this->suburbFrom, $this->stateFrom ) = $fromDetails;
	}

	/**
	 * @param array $toDetails Array of 'to' address details: [ postcode, suburb, state, ]
	 */
	public function setTo( $toDetails )
	{
		list( $this->postcodeTo, $this->suburbTo, $this->stateTo ) = $toDetails;
	}

	/**
	 * @param string Set optional parameters:
	 *   userType: 						EBAY, CORPORATE, PROMOTION, CASUAL, REFERRAL
	 *   onlineSellerID: 			Only if userType = EBAY
	 *   promotionalCode: 		Only if userType = PROMOTIONAL
	 *   receiptedDelivery: 	Customer signs to indicate receipt of package
	 *   tailLift:						For heavy items; either a tail lift truck or extra staff
	 *   transportAssurance:	If insurance is required
	 */
	
	public function setOptional( $param, $value )
	{
		$allowed = array(
			'userType' => array( 'EBAY', 'PROMOTIONAL', 'VIP' ),
			'onlineSellerId',
			'promotionalCode',
			'receiptedDelivery' => array( 'true', 'false' ),
			'tailLift' => array( 'NONE', 'PICKUP', 'DELIVERY', 'BOTH' ),
			'transportAssurance'
		);
		if( !in_array( $param, array_keys( $allowed ) ) )
		{
			echo 'Not a settable parameter';
			return;
		} 
		if( is_array( $allowed[$param] ) )
		{
			if( !in_array( $value, $allowed[$param]))
			{
				echo "'$value' is not a valid value for '$param'";
				return;
			}
		}
		$this->$param = $value;
	}

	/**
	 * Add items to be shipped
	 * 
	 * @param array $itemData [ Description, Depth, Height, Length, Weight ]
	 */
	public function addItem( array $itemData )
	{
		$descriptions = array(
			'Carton',
			'Satchel/Bag',
			'Tube',
			'Skid',
			'Pallet',
			'Crate',
			'Flat Pack',
			'Roll',
			'Length',
			'Tyre/Wheel',
			'Furniture/Bedding',
			'Envelope'
		);
		if( !in_array( $itemData['Description'], $descriptions )) throw new Exception( 'Item must be one of: ' . implode( ', ', $descriptions ) );
		$this->quoteItems[] = $itemData;
	}

	/**
	 * Retrieve official list of locations - postcode, suburb, state
	 * 
	 * @param bool $cached true (default) for returning cached data, false for fresh data
	 * 
	 */
	public function getLocations( $cached = true )
	{
		$exists = true;
		$expired = false;
		if( !file_exists( $this->locationsCacheFile ) ) $exists = false;

		// Check file age
		if( $cached && $exists )
		{
			$fileAge = time() - filemtime( $this->locationsCacheFile );
			if( $fileAge > ( $this->cacheTTL * 3600 ) )
			{
				$cached = false;
				$expired = true;
			}
		}

		if( $cached && $exists )
		{
			$this->locationList = unserialize(file_get_contents($this->locationsCacheFile));
		}
		else
		{
			$locations = $this->soapClient->GetLocations();
			foreach ($locations->GetLocationsResult->Location as $location)
			{
				$postcode = sprintf( "%04d", $location->Postcode );
				$this->locationList[$postcode][] = array( $location->Suburb, $location->State );
			}
			// Only cache to file if cached version requested, in case we wish to cache some other way.
			if( $cached && ( $expired || !$exists ) ) file_put_contents( $this->locationsCacheFile, serialize( $this->locationList ) );
		}
		return $this->locationList;
	}

	public function getState( $postcode, $town )
	{
		$locations = ( $this->locationList ) ? $this->locationList : $this->getLocations();

		foreach( $locations[$postcode] as $data )
		{
			if( strtoupper( $town ) == strtoupper( $data[0] ) ) return $data[1];
		}
	}
}
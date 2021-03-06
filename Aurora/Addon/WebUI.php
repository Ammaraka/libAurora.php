<?php
//!	@file Aurora/Addon/WebUI.php
//!	@brief WebUI code
//!	@author SignpostMarv

//	Defining exception classes in the top of the file for purposes of clarity.
namespace Aurora\Addon\WebUI{

	use Aurora\Addon\APIAccessFailedException;

	use Aurora\Addon;

//!	This interface exists purely to give client code the ability to detect all WebUI-specific exception classes in one go.
//!	The purpose of this behaviour is that instances of Aurora::Addon::WebUI::Exception will be more or less "safe" for public consumption.
	interface Exception extends Addon\Exception{
	}

//!	WebUI-specific RuntimeException
	class RuntimeException extends Addon\RuntimeException implements Exception{
	}

//!	WebUI-specific InvalidArgumentException
	class InvalidArgumentException extends Addon\InvalidArgumentException implements Exception{
	}

//!	WebUI-specific UnexpectedValueException
	class UnexpectedValueException extends Addon\UnexpectedValueException implements Exception{
	}

//!	WebUI-specific LengthException
	class LengthException extends Addon\LengthException implements Exception{
	}

//!	WebUI-specific BadMethodCallException
	class BadMethodCallException extends Addon\BadMethodCallException implements Exception{
	}
}

//!	Mimicking the layout of code in Aurora Sim here.
namespace Aurora\Addon{

	use DateTime;

	use Globals;

	use OpenMetaverse\Vector3;

	use Aurora\Framework\RegionFlags;
	use Aurora\Services\Interfaces\User;

//!	Now you might think this class should be a singleton loading config values from constants instead of a registry method, but Marv has plans. MUAHAHAHAHA.
	class WebUI extends abstractPasswordAPI{
//!	string Regular expression for validating UUIDs (put here until this operation gets performed elsewhere.
		const regex_UUID = '/^[a-fA-F0-9]{8}\-[a-fA-F0-9]{4}\-[a-fA-F0-9]{4}\-[a-fA-F0-9]{4}\-[a-fA-F0-9]{12}$/';

#region textures

//!	Returns the URI for a grid texture
/**
*	@param string $uuid texture UUID
*	@return string full URL to texture
*/
		public function GridTexture($uuid){
			if(is_string($uuid) === false){
				throw new InvalidArgumentException('Texture UUID should be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('Texture UUID was invalid.');
			}

			return $this->get_grid_info('WebUIHandlerTextureServer') . '/index.php?' . http_build_query(array( 'method'=>'GridTexture', 'uuid'=>$uuid));
		}

//!	Returns the URI for a region texture
/**
*	@param object $region instance of Aurora::Addon::WebUI::GridRegion
*	@return string full URL to texture
*/
		public function MapTexture(WebUI\GridRegion $region){
			return $region->serverURI() . '/index.php?' . http_build_query(array( 'method'=>'regionImage' . str_replace('-','', $region->RegionID())));
		}

//!	Returns the size of the specified texture
/**
*	WebUI has a call for this so we don't have to spend bandwidth on curling the texture.
*	@param string $uuid texture UUID
*	@return integer size of texture
*/
		public function GridTextureSize($uuid){
			if(is_string($uuid) === false){
				throw new InvalidArgumentException('Texture UUID should be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('Texture UUID was invalid.');
			}

			return $this->makeCallToAPI('SizeOfHTTPGetTextureImage', true, array(
				'Texture' => $uuid
			), array(
				'Size' => array('integer'=>array())
			))->Size;
		}

#endregion

#region Grid

//!	Determines the online status of the grid and whether logins are enabled.
/**
*	@return Aurora::Addon::WebUI::OnlineStatus
*	@see Aurora::Addon::WebUI::makeCallToAPI()
*/
		public function OnlineStatus(){
			$result = $this->makeCallToAPI('OnlineStatus', true, null, array(
				'Online'       => array('boolean'=>array()),
				'LoginEnabled' => array('boolean'=>array())
			));
			return new WebUI\OnlineStatus($result->Online, $result->LoginEnabled);
		}

//!	object an instance of Aurora::Addon::WebUI::GridInfo
		protected $GridInfo;

//!	Processes should not be long-lasting, so we only fetch this once.
		public function get_grid_info($info=null){
			if(isset($this->GridInfo) === false){
				$result = $this->makeCallToAPI('get_grid_info', true, null, array(
					'GridInfo' => array('object'=>array())
				));

				$this->GridInfo = WebUI\GridInfo::f();
				foreach($result->GridInfo as $k=>$v){
					$this->GridInfo[$k] = $v;
				}
			}

			return (isset($info) && is_string($info) && ctype_graph($info)) ? $this->GridInfo[$info] : $this->GridInfo;
		}

#endregion

#region Account

#region Registration

//!	Determines whether the specified username exists in the AuroraSim database.
/**
*	@param string $name the username we want to check exists
*	@return boolean TRUE if the user exists, FALSE otherwise.
*	@see Aurora::Addon::WebUI::makeCallToAPI()
*/
		public function CheckIfUserExists($name){
			if(is_string($name) === false){
				throw new InvalidArgumentException('Name should be a string');
			}
			return $this->makeCallToAPI('CheckIfUserExists', true, array('Name'=>$name), array('Verified'=>array('boolean'=>array())))->Verified;
		}

//!	Attempts to create an account with the specified details.
/**
*	@param string $Name desired account name.
*	@param string $Password plain text password (we hash it before sending)
*	@param string $Email optional, must be valid email address or an empty string if none specified
*	@param string $HomeRegion optional, must be a valid region name or an empty string if none specified
*	@param integer $userLevel
*	@param string $RLDOB User's date of birth.
*	@param string $RLFirstName User's first name.
*	@param string $RLLastName Can be an empty string to support mononyms.
*	@param string $RLAddress Postal address
*	@param string $RLCity City
*	@param string $RLZip Zip/Postal code
*	@param string $RLCountry Country of origin
*	@param string $RLIP IP Address
*	@return object An instance of Aurora::Addon::WebUI::GridUserInfo corresponding to the UUID returned by the API end point.
*/
		public function CreateAccount($Name, $Password, $Email='', $HomeRegion='', $userLevel=0, $RLDOB='1970-01-01', $RLFirstName='', $RLLastName='', $RLAddress='', $RLCity='', $RLZip='', $RLCountry='', $RLIP=''){
			if(is_string($userLevel) === true && ctype_digit($userLevel) === true){
				$userLevel = (integer)$userLevel;
			}

			if(is_string($Name) === false){
				throw new InvalidArgumentException('Username must be a string.');
			}else if($this->CheckIfUserExists($Name) === true){
				throw new InvalidArgumentException('That username has already been taken.');
			}else if(is_string($Password) === false){
				throw new InvalidArgumentException('Password must be a string.');
			}else if(strlen($Password) < 8){
				throw new LengthException('Password cannot be less than 8 characters long.');
			}else if(is_string($Email) === false){
				throw new InvalidArgumentException('Email address must be a string.');
			}else if($Email !== '' && is_email($Email) === false){
				throw new InvalidArgumentException('Email address was specified but was found to be invalid.');
			}else if(is_string($HomeRegion) === false){
				throw new InvalidArgumentException('Home Region must be a string.');
			}else if(is_integer($userLevel) === false){
				throw new InvalidArgumentException('User Level must be an integer.');
			}else if($userLevel < -1){
				throw new InvalidArgumentException('User Level must be greater than or equal to minus one.');
			}else if(is_string($RLDOB) === false){
				throw new InvalidArgumentException('User Date of Birth must be a string.');
			}else if(strtotime($RLDOB) === false){
				throw new InvalidArgumentException('User Date of Birth was not valid.');
			}else if(is_string($RLIP) === false){
				throw new InvalidArgumentException('User RL IP Address must be a string.');
			}else if(Globals::i()->registrationPostalRequired === true){
				if(is_string($RLFirstName) === false){
					throw new InvalidArgumentException('User RL First name must be a string.');
				}else if(trim($RLFirstName) === ''){
					throw new InvalidArgumentException('User RL First name must not be an empty string.');
				}else if(is_string($RLLastName) === false){
					throw new InvalidArgumentException('User RL Last name must be a string.');
				}else if(is_string($RLAddress) === false){
					throw new InvalidArgumentException('User RL Address must be a string.');
				}else if(is_string($RLCity) === false){
					throw new InvalidArgumentException('User RL City must be a string.');
				}else if(is_string($RLZip) === false){
					throw new InvalidArgumentException('User RL Zip code must be a string.');
				}else if(is_string($RLCountry) === false){
					throw new InvalidArgumentException('User RL Country must be a string.');
				}
			}

			$Name        = trim($Name);
			$Password    = '$1$' . md5($Password);
			$RLDOB      = date('Y-m-d', strtotime($RLDOB));
			$RLFirstName = trim($RLFirstName);
			$RLLastName  = trim($RLLastName);
			$RLAddress   = trim($RLAddress);
			$RLCity      = trim($RLCity);
			$RLZip       = trim($RLZip);
			$RLCountry   = trim($RLCountry);
			$RLIP        = trim($RLIP);

			$expectedResponse = array(
				'UUID' => array('string' => array())
			);
			if(Globals::i()->registrationActivationRequired === true){
				$expectedResponse['WebUIActivationToken'] = array('string' => array());
			}

			$result = $this->makeCallToAPI('CreateAccount', false, array(
				'Name'               => $Name,
				'PasswordHash'       => $Password,
				'Email'              => $Email,
				'RLDOB'              => $RLDOB,
				'RLFirstName'        => $RLFirstName,
				'RLLastName'         => $RLLastName,
				'RLAddress'          => $RLAddress,
				'RLCity'             => $RLCity,
				'RLZip'              => $RLZip,
				'RLCountry'          => $RLCountry,
				'RLIP'               => $RLIP,
				'ActivationRequired' => (Globals::i()->registrationActivationRequired === true)
			),	$expectedResponse);

			$ActivationToken = null;
			if(preg_match(self::regex_UUID, $result->UUID) === false){
				throw new UnexpectedValueException('Call to API was successful, but UUID response was not a valid UUID.');
			}else if($result->UUID === '00000000-0000-0000-0000-000000000000'){
				throw new UnexpectedValueException('Call to API was successful but registration failed.');
			}else if(Globals::i()->registrationActivationRequired === true){
				if(preg_match(self::regex_UUID, $result->WebUIActivationToken) !== 1){
					throw new UnexpectedValueException('Call to API was successful, but activation token was not a valid UUID.');
				}else{
					$ActivationToken = $result->WebUIActivationToken;
				}
			}
			return array($this->GetGridUserInfo($result->UUID), $ActivationToken);
		}

//!	Attempt to fetch the public avatar archives.
/**
*	@return an instance of Aurora::Addon::WebUI::AvatarArchives corresponding to the result returned by the API end point.
*/
		public function GetAvatarArchives(){
			$result = $this->makeCallToAPI('GetAvatarArchives', false, null, array(
				'names'    => array('array'=>array()),
				'snapshot' => array('array'=>array())
			));

			if(count($result->names) !== count($result->snapshot)){
				throw new LengthException('Call to API was successful, but the number of names did not match the number of snapshots');
			}

			$archives = array();
			foreach($result->names as $k=>$v){
				$archives[] = new WebUI\basicAvatarArchive($v, $result->snapshot[$k]);
			}

			return new WebUI\AvatarArchives($archives);
		}

//!	Determines whether or not the account has been authenticated/verified.
/**
*	@param mixed $uuid either a string UUID of the user we wish to check, or an instance of Aurora::Services::Interfaces::User
*	@return boolean TRUE if the account has been authenticated/verified, FALSE otherwise.
*/
		public function Authenticated($uuid){
			if($uuid instanceof User){
				$uuid = $uuid->PrincipalID();
			}
			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID should be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('UUID supplied was not a valid UUID.');
			}
			$result = $this->makeCallToAPI('Authenticated', false, array('UUID'=>$uuid), array(
				'Verified' => array('boolean'=>array())
			));
			return $result->Verified;
		}

//!	Attempts to activate the account via an activation token.
/**
*	@param string $username Account username
*	@param string $password Account password
*	@param string $token Activation token
*/
		public function ActivateAccount($username, $password, $token){
			if(is_string($username) === false){
				throw new InvalidArgumentException('Username must be a string.');
			}else if($this->CheckIfUserExists($username) === false){
				throw new InvalidArgumentException('Cannot activate a non-existant account.');
			}else if(is_string($password) === false){
				throw new InvalidArgumentException('Password must be a string.');
			}else if(is_string($token) === false){
				throw new InvalidArgumentException('Token must be a string.');
			}else if(preg_match(self::regex_UUID, $token) !== 1){
				throw new InvalidArgumentException('Token must be a valid UUID.');
			}
			$password = '$1$' . md5($password);

			$result = $this->makeCallToAPI('ActivateAccount', false, array('UserName' => $username, 'PasswordHash' => $password, 'ActivationToken' => $token), array(
				'Verified' => array('boolean'=>array())
			));

			return $result->Verified;
		}

#endregion

#region Login

//!	Since admin login and normal login have the same response, we're going to use the same code for both here.
/**
*	@param string $username
*	@param string $password
*	@param boolean $asAdmin TRUE if attempt to login as admin, FALSE otherwise. defaults to FALSE.
*	@return object instance of Aurora::Addon::WebUI::genUser
*/
		private function doLogin($username, $password, $asAdmin=false){
			if(is_string($username) === false){
				throw new InvalidArgumentException('Username must be string.');
			}else if(trim($username) === ''){
				throw new InvalidArgumentException('Username was an empty string');
			}else if(is_string($password) === false){
				throw new InvalidArgumentException('Password must be a string');
			}else if(trim($password) === ''){
				throw new InvalidArgumentException('Password was an empty string');
			}
			$password = '$1$' . md5($password); // this is required so we don't have to transmit the plaintext password.
			$result = $this->makeCallToAPI($asAdmin ? 'AdminLogin' : 'Login', false, array('Name' => $username, 'Password' => $password), array(
				'Verified'  => array('boolean'=>array())
			));
			if($result->Verified === false){
				throw new InvalidArgumentException('Credentials incorrect');
			}else if(isset($result->UUID, $result->FirstName, $result->LastName) === false){
				throw new UnexpectedValueException('API call was made, credentials were correct but required response properties were missing');
			}
			return WebUI\genUser::r($result->UUID, $result->FirstName, $result->LastName); // we're leaving validation up to the genUser class.
		}

//!	Attempts a login as a normal user.
/**
*	@param string $username
*	@param string $password
*	@return object instance of Aurora::Addon::WebUI::genUser
*/
		public function Login($username, $password){
			return $this->doLogin($username, $password);
		}

//!	Attempts to login as an admin user.
/**
*	@param string $username
*	@param string $password
*	@return object instance of Aurora::Addon::WebUI::genUser
*/
		public function AdminLogin($username, $password){
			return $this->doLogin($username, $password, true);
		}

//!	Attempt to set the WebLoginKey for the specified user
/**
*	@param string $for UUID of the desired user to specify a WebLoginKey for.
*	@return string the WebLoginKey generated by the server.
*	@see Aurora::Addon::WebUI::makeCallToAPI()
*/
		public function SetWebLoginKey($for){
			if(is_string($for) === false){
				throw new InvalidArgumentException('UUID of user must be specified as a string');
			}else if(preg_match(self::regex_UUID, $for) !== 1){
				throw new InvalidArgumentException('Specified string was not a valid UUID');
			}
			$result = $this->makeCallToAPI('SetWebLoginKey', false, array('PrincipalID'=>$for), array(
				'WebLoginKey' => array('string'=>array())
			));
			if(preg_match(self::regex_UUID, $result->WebLoginKey) !== 1){
				throw new UnexpectedValueException('WebLoginKey value present on API result, but value was not a valid UUID.');
			}
			return $result->WebLoginKey;
		}

#endregion

#region Email

//!	Save email address, set user level to zero.
/**
*	@param mixed $uuid either a string UUID or an instance of Aurora::Services::Interfaces::User of the user we wish to save the email address for.
*	@param string $email email address.
*	@return boolean TRUE if successful, FALSE otherwise.
*/
		public function SaveEmail($uuid, $email){
			if($uuid instanceof User){
				$uuid = $uuid->PrincipalID();
			}

			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID must be a string.');
			}else if(preg_match(self::regex_UUID, $uuid)!== 1){
				throw new InvalidArgumentException('UUID was not a valid UUID.');
			}else if(is_string($email) === false){
				throw new InvalidArgumentException('Email address must be a string.');
			}else if(is_email($email) === false){
				throw new InvalidArgumentException('Email address was not valid.');
			}

			$result = $this->makeCallToAPI('SaveEmail', false, array('UUID' => $uuid, 'Email' => $email), array(
				'Verified' => array('boolean'=>array())
			));

			return $result->Verified;
		}

//!	Confirm the account name and email address (used by forgotten password activities)
/**
*	@param string $name Account name
*	@param string $email Account email address
*	@return boolean TRUE if successful, FALSE otherwise.
*/
		public function ConfirmUserEmailName($name, $email){
			if(is_string($name) === true){
				$name = trim($name);
			}

			if(is_string($name) === false){
				throw new InvalidArgumentException('Name must be a string.');
			}else if($name === ''){
				throw new InvalidArgumentException('Name cannot be an empty string.');
			}else if(is_string($email) === false){
				throw new InvalidArgumentException('Email address must be a string.');
			}else if(is_email($email) === false){
				throw new InvalidArgumentException('Email address is invalid.');
			}

			$result = $this->makeCallToAPI('ConfirmUserEmailName', false, array('Name' => $name, 'Email' => $email), array(
				'Verified'=>array('boolean'=>array())
			));
			if(isset($result->ErrorCode) === true && is_integer($result->ErrorCode) === false){
				throw new UnexpectedValueException('Call to API was successful but required response property was of unexpected type.',2);
			}else if(isset($result->ErrorCode) === true){
				if($result->ErrorCode === 1){
					throw new InvalidArgumentException('No account was found with the specified name.');
				}else if($result->ErrorCode === 2){
					throw new InvalidArgumentException('The specified account is disabled.');
				}else if($result->ErrorCode === 3){
					throw new InvalidArgumentException('The specified email address does not match the email address associated with the specified account.');
				}else{
					throw new UnexpectedValueException('Unknown error occurred when checking email address of specified account.');
				}
			}

			return $result->Verified;
		}

#endregion

#region password

//!	Change password.
/**
*	@param mixed $uuid either a string UUID or an instance of Aurora::Services::Interfaces::User of the user we wish to change the password for.
*	@param mixed $oldPassword old password
*	@param mixed $newPassword new password
*/
		public function ChangePassword($uuid, $oldPassword, $newPassword){
			if($uuid instanceof User){
				$uuid = $uuid->PrincipalID();
			}

			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID must be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('UUID was not a valid UUID.');
			}else if(is_string($oldPassword) === false){
				throw new InvalidArgumentException('Old password must be a string.');
			}else if(is_string($newPassword) === false){
				throw new InvalidArgumentException('New password must be a string.');
			}else if(trim($newPassword) === ''){
				throw new InvalidArgumentException('New password cannot be an empty string.');
			}else if(strlen($newPassword) < 8){
				throw new LengthException('New password cannot be less than 8 characters long.');
			}

			return $this->makeCallToAPI('ChangePassword', false, array(
				'UUID'        => $uuid,
				'Password'    => '$1$' . (substr($oldPassword, 0, 3) === '$1$' ? substr($oldPassword, 3) : md5($oldPassword)),
				'NewPassword' => '$1$' . (substr($newPassword, 0, 3) === '$1$' ? substr($newPassword, 3) : md5($newPassword))
			), array(
				'Verified' => array('boolean'=>array())
			))->Verified;
		}

//!	Change password without old password
/**
*	@param mixed $uuid either a string UUID or an instance of Aurora::Services::Interfaces::User of the user we wish to change the password for.
*	@param mixed $newPassword new password
*/
		public function ForgotPassword($uuid, $newPassword){
			if($uuid instanceof User){
				$uuid = $uuid->PrincipalID();
			}

			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID must be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('UUID was not a valid UUID.');
			}else if(is_string($newPassword) === false){
				throw new InvalidArgumentException('New password must be a string.');
			}else if(trim($newPassword) === ''){
				throw new InvalidArgumentException('New password cannot be an empty string.');
			}else if(strlen($newPassword) < 8){
				throw new LengthException('New password cannot be less than 8 characters long.');
			}

			return $this->makeCallToAPI('ForgotPassword', false, array(
				'UUID'        => $uuid,
				'Password' => '$1$' . (substr($newPassword, 0, 3) === '$1$' ? substr($newPassword, 3) : md5($newPassword))
			), array(
				'Verified' => array('boolean'=>array())
			))->Verified;
		}

#endregion

//!	Change account name.
/**
*	@param mixed $uuid either a string UUID or an instance of Aurora::Services::Interfaces::User of the user we wish to change the name for.
*	@param string $name new name
*/
		public function ChangeName($uuid, $name){
			if($uuid instanceof User){
				$uuid = $uuid->PrincipalID();
			}
			if(is_string($name) === true){
				$name = trim($name);
			}

			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID must be a string.');
			}else if(preg_match(self::regex_UUID, $uuid)!== 1){
				throw new InvalidArgumentException('UUID was not a valid UUID.');
			}else if(is_string($name) === false){
				throw new InvalidArgumentException('Name must be a string.');
			}else if($name === ''){
				throw new InvalidArgumentException('Name cannot be an empty string.');
			}

			if($this->GetGridUserInfo($uuid)->Name() === $name){ // if the name is already the same, we're not going to bother making the call.
				return true;
			}

			$result = $this->makeCallToAPI('ChangeName', false, array('UUID' => $uuid, 'Name' => $name), array(
				'Verified' => array('boolean'=>array()),
				'Stored'   => array('boolean'=>array())
			));
			if($result->Verified === true && $result->Stored === false){
				throw new RuntimeException('Call to API was successful, but name change was not stored by the server.');
			}

			return $result->Verified;
		}

//!	Attempt to edit the account name, email address and real-life info.
/**
*	If $uuid is an instance of Aurora::Addon::WebUI::abstractUser, $name is set to Aurora::Addon::WebUI::abstractUser::Name() and $uuid is set to Aurora::Addon::WebUI::abstractUser::PrincipalID()
*	@param mixed $uuid either the account ID or an instance of Aurora::Addon::WebUI::abstractUser
*	@param mixed $name either a string of the account name or NULL when $uuid is an instance of Aurora::Addon::WebUI::abstractUser
*	@param string $email Email address for the account
*	@param mixed $RLInfo either an instance of Aurora::Addon::WebUI::RLInfo or NULL
*	@param mixed $userLevel NULL or integer user level
*	@return boolean TRUE if successful, FALSE otherwise. Also returns FALSE if the operation was partially successful.
*/
		public function EditUser($uuid, $name=null, $email='', WebUI\RLInfo $RLInfo=null, $userLevel=null){
			if($uuid instanceof WebUI\abstractUser){
				if(is_null($name) === true){
					$name = $uuid->Name();
				}
				$uuid = $uuid->PrincipalID();
			}
			if(is_string($name)){
				$name = trim($name);
			}
			if(is_string($email)){
				$email = trim($email);
			}
			if(isset($userLevel) === true && is_string($userLevel) === true && ctype_digit($userLevel) === true){
				$userLevel = (integer)$userLevel;
			}

			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID must be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) === false){
				throw new InvalidArgumentException('UUID was not a valid UUID.');
			}else if(is_string($name) === false){
				throw new InvalidArgumentException('Name must be a string.');
			}else if($name === ''){
				throw new InvalidArgumentException('Account name cannot be an empty string.');
			}else if(is_string($email) === false){
				throw new InvalidArgumentException('Email address must be a string.');
			}else if($email !== '' && is_email($email) === false){
				throw new InvalidArgumentException('Email address was not valid.');
			}else if(isset($userLevel) === true && is_integer($userLevel) === false){
				throw new InvalidArgumentException('User Level must be specified as integer.');
			}

			$data = array(
				'UserID' => $uuid,
				'Name'   => $name,
				'Email'  => $email
			);
			if($RLInfo instanceof WebUI\RLInfo){
				foreach($RLInfo as $k=>$v){
					$data[$k] = $v;
				}
			}
			if(isset($userLevel) === true){
				$data['UserLevel'] = $userLevel;
			}

			$result = $this->makeCallToAPI('EditUser', false, $data, array(
				'agent'   => array('boolean'=>array()),
				'account' => array('boolean'=>array())
			));

			return ($result->agent && $result->account);
		}

//!	Attempt to reset the user's avatar
/**
*	@param mixed $uuid either the account ID or an instance of Aurora::Addon::WebUI::abstractUser
*/
		public function ResetAvatar($uuid){
			if($uuid instanceof WebUI\abstractUser){
				if(is_null($name) === true){
					$name = $uuid->Name();
				}
				$uuid = $uuid->PrincipalID();
			}

			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID must be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) === false){
				throw new InvalidArgumentException('UUID was not a valid UUID.');
			}

			return $this->makeCallToAPI('ResetAvatar', false, array(
				'User' => $uuid
			), array(
				'Success' => array('boolean' => array())
			))->Success;
		}

#endregion

#region Users

//!	Validator array to be used with Aurora::Addon::WebUI::makeCallToAPI()
/**
*	Since we get serialized GridUserInfo objects in both single-result API calls and iterator result calls, we avoid duplication of the validator array by moving it into a static method.
*	@return array Validator array to be used with Aurora::Addon::WebUI::makeCallToAPI()
*	@see Aurora::Addon::WebUI::GetGridUserInfo()
*	@see Aurora::Addon::WebUI::RecentlyOnlineUsers()
*/
		protected static function GridUserInfoValidator(){
			static $validator = array(
				'UUID'              => array('string' =>array()),
				'HomeUUID'          => array('string' =>array()),
				'HomeName'          => array('string' =>array()),
				'CurrentRegionUUID' => array('string' =>array()),
				'CurrentRegionName' => array('string' =>array()),
				'Online'            => array('boolean'=>array()),
				'Email'             => array('string' =>array()),
				'Name'              => array('string' =>array()),
				'FirstName'         => array('string' =>array()),
				'LastName'          => array('string' =>array()),
				'LastLogin'         => array('boolean'=>array(false), 'integer'=>array()),
				'LastLogout'        => array('boolean'=>array(false), 'integer'=>array()),
			);
			return $validator;
		}

//!	Get the GetGridUserInfo for the specified user.
/**
*	@param mixed $uuid either a string UUID of the user we wish to check, or an instance of Aurora::Services::Interfaces::User
*	@return object Aurora::Addon::WebUI::GridUserInfo
*/
		public function GetGridUserInfo($uuid){
			if($uuid instanceof User){
				$uuid = $uuid->PrincipalID();
			}
			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID should be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('UUID supplied was not a valid UUID.');
			}
			$result = $this->makeCallToAPI('GetGridUserInfo', true, array('UUID'=>$uuid), static::GridUserInfoValidator());
			// this is where we get lazy and leave validation up to the GridUserInfo class.
			return	WebUI\GridUserInfo::r(
				$result->UUID,
				$result->Name,
				$result->HomeUUID,
				$result->HomeName,
				$result->CurrentRegionUUID,
				$result->CurrentRegionName,
				$result->Online,
				$result->Email,
				$result->LastLogin === false ? null : $result->LastLogin,
				$result->LastLogout === false ? null : $result->LastLogout
			);
		}

//!	Attempt to get the profile object for the specified user.
/**
*	If $name is an instance of Aurora::Addon::WebUI::abstractUser, $uuid will be set to Aurora::Addon::WebUI::abstractUser::PrincipalID() and $name will be set to an empty string.
*	@param mixed $name Either a string of the account name, or an instance of Aurora::Addon::WebUI::abstractUser
*	@param string $uuid Account UUID
*	@return object instance of Aurora::Addon::WebUI::UserProfile
*/
		public function GetProfile($name='', $uuid='00000000-0000-0000-0000-000000000000'){
			if($name instanceof WebUI\abstractUser){
				$uuid = $name->PrincipalID();
				$name = '';
			}
			if(is_string($name) === false){
				throw new InvalidArgumentException('Account name must be a string.');
			}else if(is_string($uuid) === false){
				throw new InvalidArgumentException('Account UUID must be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('Account UUID was not a valid UUID.');
			}

			$result = $this->makeCallToAPI('GetProfile', true, array('Name' => $name, 'UUID' => $uuid), array(
				'account'=> array('object'=>array(array(
					'Created'          => array('integer'=>array()),
					'Name'             => array('string'=>array()),
					'PrincipalID'      => array('string'=>array()),
					'Email'            => array('string'=>array()),
					'TimeSinceCreated' => array('string'=>array()),
					'UserLevel'        => array('integer'=>array()),
					'UserFlags'        => array('integer'=>array())
				)))
			));

			$account = $result->account;

			$allowPublish = $maturePublish  = $visible     = false;
			$wantToMask   = $canDoMask      = 0;
			$wantToText   = $canDoText      = $languages   = $aboutText = $firstLifeAboutText = $webURL = $displayName = $customType = '';
			$image        = $firstLifeImage = '00000000-0000-0000-0000-000000000000';
			$notes        = json_encode('');
			$userLevel    = $account->UserLevel;
			$userFlags    = $account->UserFlags;
			$accountFlags = 0;
			if(isset($result->profile) === true){
				$profile = $result->profile;
				if(isset(
					$profile->AllowPublish, $profile->MaturePublish, $profile->Visible,
					$profile->WantToMask, $profile->CanDoMask,
					$profile->WantToText, $profile->CanDoText, $profile->Languages, $profile->AboutText, $profile->FirstLifeAboutText, $profile->WebURL, $profile->DisplayName, $profile->CustomType,
					$profile->Image, $profile->FirstLifeImage,
					$profile->Notes
				) === false){
					throw new UnexpectedValueException('Call to API was successful, but optional response properties were missing.');
				}

				$allowPublish       = $profile->AllowPublish;
				$maturePublish      = $profile->MaturePublish;
				$visible            = $profile->Visible;
				$wantToMask         = $profile->WantToMask;
				$canDoMask          = $profile->CanDoMask;
				$wantToText         = $profile->WantToText;
				$canDoText          = $profile->CanDoText;
				$languages          = $profile->Languages;
				$aboutText          = $profile->AboutText;
				$firstLifeAboutText = $profile->FirstLifeAboutText;
				$webURL             = $profile->WebURL;
				$displayName        = $profile->DisplayName;
				$customType         = $profile->CustomType;
				$image              = $profile->Image;
				$firstLifeImage     = $profile->FirstLifeImage;
				$notes              = $profile->Notes;
			}

			$RLName = $RLAddress = $RLZip = $RLCity = $RLCountry = null;
			if(isset($result->agent) === true){
				$agent = $result->agent;
				$properties = array(
					'Flags',
					'RLName',
					'RLAddress',
					'RLZip',
					'RLCity',
					'RLCountry'
				);
				foreach($properties as $v){
					if(property_exists($result->agent, $v) === false){
						throw new UnexpectedValueException('Call to API was successful, but optional response properties were missing.');
					}
				}

				$RLName       = $agent->RLName;
				$RLAddress    = $agent->RLAddress;
				$RLZip        = $agent->RLZip;
				$RLCity       = $agent->RLCity;
				$RLCountry    = $agent->RLCountry;
				$accountFlags = $agent->Flags;
			}
			return WebUI\UserProfile::r($account->PrincipalID, $account->Name, $account->Email, $account->Created, $allowPublish, $maturePublish, $wantToMask, $wantToText, $canDoMask, $canDoText, $languages, $image, $aboutText, $firstLifeImage, $firstLifeAboutText, $webURL, $displayName, isset($account->PartnerUUID) ? $account->PartnerUUID : '00000000-0000-0000-0000-000000000000', $visible, $customType, $notes, $userLevel, $userFlags, $accountFlags, $RLName, $RLAddress, $RLZip, $RLCity, $RLCountry);
		}

//!	Attempt to delete the user
/**
*	If $uuid is an instance of Aurora::Addon::WebUI::abstractUser, $uuid is set to Aurora::Addon::WebUI::abstractUser::PrincipalID()
*	@param mixed $uuid Either an account UUID, or an instance of Aurora::Addon::WebUI::abstractUser
*	@return boolean Should always return TRUE
*/
		public function DeleteUser($uuid){
			if($uuid instanceof WebUI\abstractUser){
				$uuid = $uuid->PrincipalID();
			}
			if(is_string($uuid) === true){
				$uuid = trim($uuid);
			}

			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID must be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) === false){
				throw new InvalidArgumentException('UUID was not a valid UUID.');
			}

			$result = $this->makeCallToAPI('DeleteUser', false, array('UserID' => $uuid), array(
				'Finished' => array('boolean'=>array())
			));

			return $result->Finished;
		}

//!	Set the home location for the specified user.
/**
*	@param string $uuid UUID of user
*	@param mixed $region An instance of Aurora::Services::Interfaces::GridRegion or null, the new home region
*	@param mixed $position An instance of OpenMetaverse::Vector3 or null, the new home location
*	@param mixed $lookAt An instance of OpenMetaverse::Vector3 or null, the new focal point for the home location
*/
		public function SetHomeLocation($uuid, \Aurora\Services\Interfaces\GridRegion $region=null, \OpenMetaverse\Vector3 $position=null, \OpenMetaverse\Vector3 $lookAt=null){
			if($uuid instanceof User){
				$uuid = $uuid->PrincipalID();
			}
			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID should be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('UUID supplied was not a valid UUID.');
			}

			$input = array(
				'User' => $uuid
			);
			if(isset($region) === true){
				$input['RegionID'] = $region->RegionID();
			}
			if(isset($position) === true){
				$input['Position'] = (string)$position;
			}
			if(isset($lookAt) === true){
				$input['LookAt'] = (string)$lookAt;
			}

			return $this->makeCallToAPI('SetHomeLocation', false, $input, array(
				'Success' => array('boolean' => array())
			))->Success;
		}

#region banning

//!	Attempts to ban a user permanently or temporarily
/**
*	If $uuid is an instance of Aurora::Addon::WebUI::abstractUser, $uuid is set to Aurora::Addon::WebUI::abstractUser::PrincipalID()
*	If $until is an instance of DateTime, $until is set to DateTime::format('c')
*	@param mixed $uuid Either an account UUID, or an instance of Aurora::Addon::WebUI::abstractUser
*	@param mixed $until Either NULL (in which case it's a permanent ban) or if a temporary ban should be an instance of DateTime or a date string.
*	@return boolean
*/
		public function BanUser($uuid, $until=null){
			if($uuid instanceof WebUI\abstractUser){
				$uuid = $uuid->PrincipalID();
			}
			if($until instanceof DateTime){
				$until = $until->format('c');
			}

			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID must be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('UUID was not a valid UUID.');
			}else if(isset($until) === true){
				if(is_string($until) === false){
					throw new InvalidArgumentException('temporary ban date must be a string.');
				}else if(strtotime($until) === false){
					throw new InvalidArgumentException('temporary ban date must be a valid date.');
				}
			}

			$result = $this->makeCallToAPI(isset($until) ? 'TempBanUser' : 'BanUser', false, array('UserID' => $uuid, 'BannedUntil' => $until), array(
				'Finished' => array('boolean'=>array())
			));

			return $result->Finished;
		}

//!	Attempts to temporarily ban a user.
/**
*	This method is only here for completeness, in practice Aurora::Addon::WebUI::BanUser() should be called with $until specified
*	If $uuid is an instance of Aurora::Addon::WebUI::abstractUser, $uuid is set to Aurora::Addon::WebUI::abstractUser::PrincipalID()
*	If $until is an instance of DateTime, $until is set to DateTime::format('c')
*	@param mixed $uuid Either an account UUID, or an instance of Aurora::Addon::WebUI::abstractUser
*	@param mixed $until should be an instance of DateTime or a date string.
*	@return boolean
*/
		public function TempBanUser($uuid, $until){
			if(isset($until) === false){
				throw new InvalidArgumentException('Temporary ban time must be specified.');
			}

			return $this->BanUser($uuid, $until);
		}

//!	Attempts to unban a user.
/**
*	If $uuid is an instance of Aurora::Addon::WebUI::abstractUser, $uuid is set to Aurora::Addon::WebUI::abstractUser::PrincipalID()
*	@param mixed $uuid Either an account UUID, or an instance of Aurora::Addon::WebUI::abstractUser
*	@return boolean
*/
		public function UnBanUser($uuid){
			if($uuid instanceof WebUI\abstractUser){
				$uuid = $uuid->PrincipalID();
			}

			if(is_string($uuid) === false){
				throw new InvalidArgumentException('UUID must be a string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('UUID must be a valid UUID.');
			}

			$result = $this->makeCallToAPI('UnBanUser', false, array('UserID' => $uuid), array(
				'Finished' => array('boolean'=>array())
			));

			return $result->Finished;
		}

#endregion

//!	Attempt to search for users
/**
*	@param string $query search filter
*	@param integer $start an integer start point for results
*	@param integer $count maximum number of results to fetch in a single batch
*	@param boolean $asArray controls whether to return an iterator object or a raw result array.
*	@return object an instance of Aurora::Addon::WebUI::SearchUserResults
*/
		public function FindUsers($query='', $start=0, $count=25, $asArray=false){
			if(is_string($start) === true && ctype_digit($start) === true){
				$start = (integer)$start;
			}
			if(is_string($count) === true && ctype_digit($count) === true){
				$count = (integer)$count;
			}
			if(is_string($query) === true){
				$query = trim($query);
			}

			if(is_integer($start) === false){
				throw new InvalidArgumentException('Start point must be an integer.');
			}else if(is_integer($count) === false){
				throw new InvalidArgumentException('Count point must be an integer.');
			}else if(is_string($query) === false){
				throw new InvalidArgumentException('Query filter must be a string.');
			}

			$has = WebUI\SearchUserResults::hasInstance($this, $query);
			$results = array();

			if($asArray == true || $has === false){
				$result = $this->makeCallToAPI('FindUsers', true, array(
					'Start'=>$start,
					'Count'=>$count,
					'Query'=>$query
				), array(
					'Users' => array('array'=>array(array(
						'object' => array(array(
							'PrincipalID' => array('string'=>array()),
							'UserName'    => array('string'=>array()),
							'Created'     => array('integer'=>array()),
							'UserFlags'   => array('integer'=>array()),
							'UserLevel'   => array('integer'=>array()),
							'Flags'       => array('integer'=>array())
						))
					))),
					'Total' => array('integer'=>array())
				));

				foreach($result->Users as $userdata){
					$results[] = WebUI\SearchUser::r($userdata->PrincipalID, $userdata->UserName, $userdata->Created, $userdata->UserFlags, $userdata->UserLevel, $userdata->Flags);
				}
			}


			return $asArray ? $results : WebUI\SearchUserResults::r($this, $query, $start, $has ? null : $result->Total, $results);
		}

//!	returns the friends list for the specified user.
/**
*	@param mixed $forUser Either a UUID string or an instance of Aurora::Addon::WebUI::abstractUser
*	@return object instance of Aurora::Addon::WebUI::FriendsList
*/
		public function GetFriends($forUser){
			if(($forUser instanceof WebUI\abstractUser) === false){
				if(is_string($forUser) === false){
					throw new InvalidArgumentException('forUser must be a string.');
				}else if(preg_match(self::regex_UUID, $forUser) !== 1){
					throw new InvalidArgumentException('forUser must be a valid UUID.');
				}
				$forUser = $this->GetProfile('', $forUser);
			}

			$result = $this->makeCallToAPI('GetFriends', true, array('UserID' => $forUser->PrincipalID()), array(
				'Friends' => array('array'=>array())
			));
			$response = array();
			foreach($result->Friends as $v){
				if(isset($v->PrincipalID, $v->Name, $v->MyFlags, $v->TheirFlags) === false){
					throw new UnexpectedValueException('Call to API was successful, but required response sub-properties were missing.');
				}
				$response[] = WebUI\FriendInfo::r($forUser, $v->PrincipalID, $v->Name, $v->MyFlags, $v->TheirFlags);
			}

			return new WebUI\FriendsList($response);
		}

#region statistics

//!	Attempt to get the number of recently online users filtering the query by the method arguments
/**
*	@param integer $secondsAgo The maximum number of seconds ago that the user would have logged in to include in the query
*	@param boolean $stillOnline TRUE includes users still online, FALSE otherwise
*	@return integer The number of recently online users filtered by the method arguments
*/
		public function NumberOfRecentlyOnlineUsers($secondsAgo=0, $stillOnline=false){
			if(is_string($secondsAgo) && ctype_digit($secondsAgo) === true){
				$secondsAgo = (integer)$secondsAgo;
			}

			if(is_integer($secondsAgo) === false){
				throw new InvalidArgumentException('secondsAgo must be specified as integer.');
			}else if($secondsAgo < 0){
				throw new InvalidArgumentException('secondsAgo must be greater than or equal to zero.');
			}else if(is_bool($stillOnline) === false){
				throw new InvalidArgumentException('stillOnline must be specified as a boolean.');
			}
			return $this->makeCallToAPI('NumberOfRecentlyOnlineUsers', true, array(
				'secondsAgo'  => $secondsAgo,
				'stillOnline' => $stillOnline ? 1 : 0
			), array(
				'result' => array('integer'=>array())
			))->result;
		}

//!	Attempt to get a list of recently online users
/**
*	@param integer $secondsAgo The maximum number of seconds ago that the user would have logged in to include in the query
*	@param boolean $stillOnline TRUE includes users still online, FALSE otherwise
*	@param integer $start an integer start point for results
*	@param integer $count maximum number of results to fetch in a single batch
*	@param boolean $asArray controls whether to return an iterator object or a raw result array.
*/
		public function RecentlyOnlineUsers($secondsAgo=0, $stillOnline=false, $start=0, $count=10, $asArray=false){
			if(is_string($secondsAgo) && ctype_digit($secondsAgo) === true){
				$secondsAgo = (integer)$secondsAgo;
			}
			if(is_string($start) && ctype_digit($start) === true){
				$start = (integer)$start;
			}
			if(is_string($count) && ctype_digit($count) === true){
				$count = (integer)$count;
			}


			if(is_integer($start) === false){
				throw new InvalidArgumentException('Start point must be an integer.');
			}else if(is_integer($count) === false){
				throw new InvalidArgumentException('Maximum batch count must be an integer.');
			}else if(is_integer($secondsAgo) === false){
				throw new InvalidArgumentException('secondsAgo must be specified as integer.');
			}else if($secondsAgo < 0){
				throw new InvalidArgumentException('secondsAgo must be greater than or equal to zero.');
			}else if(is_bool($stillOnline) === false){
				throw new InvalidArgumentException('stillOnline must be specified as a boolean.');
			}
			$result = $this->makeCallToAPI('RecentlyOnlineUsers', true, array(
				'secondsAgo'  => $secondsAgo,
				'stillOnline' => $stillOnline ? 1 : 0,
				'Start'       => $start,
				'Count'       => $count
			), array(
				'Start' => array('integer'=>array()),
				'Count' => array('integer'=>array()),
				'Total' => array('integer'=>array()),
				'Users' => array('array'  =>array(array(
					'object' => array(static::GridUserInfoValidator())
				)))
			));

			$users = array();
			foreach($result->Users as $user){
				$users[] = WebUI\GridUserInfo::r(
					$user->UUID,
					$user->Name,
					$user->HomeUUID,
					$user->HomeName,
					$user->CurrentRegionUUID,
					$user->CurrentRegionName,
					$user->Online,
					$user->Email,
					$user->LastLogin  === false ? null : $user->LastLogin,
					$user->LastLogout === false ? null : $user->LastLogout
				);
			}

			return $asArray ? $users : WebUI\RecentlyOnlineUsersIterator::f($this, $secondsAgo, $stillOnline, $start, $result->Total, $users);
		}

#endregion

#endregion

#region IAbuseReports

//!	Validator array to be used with Aurora::Addon::WebUI::makeCallToAPI()
/**
*	@return array Validator array to be used with Aurora::Addon::WebUI::makeCallToAPI()
*	@see Aurora::Addon::WebUI::GetAbuseReports()
*	@see Aurora::Addon::WebUI::GetAbuseReport()
*/
		protected static function GetAbuseReportValidator(){
			static $validator = array(
				'AbuseDetails'   => array('string' =>array()),
				'AbuseLocation'  => array('string' =>array()),
				'AbuserName'     => array('string' =>array()),
				'AbuseSummary'   => array('string' =>array()),
				'Active'         => array('boolean'=>array()),
				'AssignedTo'     => array('string' =>array()),
				'Category'       => array('string' =>array()),
				'Checked'        => array('boolean'=>array()),
				'Notes'          => array('string' =>array()),
				'Number'         => array('integer'=>array()),
				'ObjectName'     => array('string' =>array()),
				'ObjectPosition' => array('string' =>array()),
				'ObjectUUID'     => array('string' =>array()),
				'RegionName'     => array('string' =>array()),
				'ReporterName'   => array('string' =>array()),
				'ScreenshotID'   => array('string' =>array())
			);
			return $validator;
		}

//!	Attempt to fetch all Abuse Reports.
/**
*	@param integer $start start point for abuse reports
*	@param integer $count maximum number of abuse reports to retrieve
*	@param boolean $active TRUE to get open abuse reports, FALSE to get closed abuse reports
*/
		public function GetAbuseReports($start=0, $count=25, $active=true){
			if(is_integer($start) === false){
				throw new InvalidArgumentException('Start point must be an integer.');
			}else if(is_integer($count) === false){
				throw new InvalidArgumentException('Maximum abuse report count must be an integer.');
			}else if(is_bool($active) === false){
				throw new InvalidArgumentException('Activity flag must be a boolean.');
			}

			$result = $this->makeCallToAPI('GetAbuseReports', true, array(
					'Start'       => $start,
					'Count'       => $count,
					'Active'      => $active
				), array(
				'AbuseReports' => array('array'=>array(array(
					'object' => array(static::GetAbuseReportValidator())
				)))
			));

			$results = array();
			foreach($result->AbuseReports as $AR){
				$results[] = WebUI\AbuseReport::r($AR);
			}

			return new WebUI\AbuseReports($results);
		}

//!	Attempts to fetch the specific Abuse Report
/**
*	@param integer $id
*	@return object an instance of Aurora::Addon::WebUI::AbuseReport
*/
		public function GetAbuseReport($id){
			if(is_string($id) === true && ctype_digit($id) === true){
				$id = (integer)$id;
			}

			if(is_integer($id) === false){
				throw new InvalidArgumentException('Abuse Report ID must be specified as integer.');
			}

			return WebUI\AbuseReport::r($this->makeCallToAPI('GetAbuseReport', true, array(
					'AbuseReport' => $id
				), array(
					'AbuseReport' => array(array('object'=>static::GetAbuseReportValidator()))
			))->AbuseReport);
		}

//!	Attempts to mark the specified Abuse Report as complete
/**
*	@param mixed $abuseReport Either an integer corresponding to Aurora::Addon::WebUI::AbuseReport::Number() or an instance of Aurora::Addon::WebUI::AbuseReport
*	@return boolean TRUE on success, FALSE on failure (usually because the specified abuse report doesn't exist).
*/
		public function AbuseReportMarkComplete($abuseReport){
			if($abuseReport instanceof WebUI\AbuseReport){
				$abuseReport = $abuseReport->Number();
			}else if(is_string($abuseReport) === true && ctype_digit($abuseReport) === true){
				$abuseReport = (integer)$abuseReport;
			}

			if(is_integer($abuseReport) === false){
				throw new InvalidArgumentException('Abuse report number must be specified as an integer.');
			}

			$result = $this->makeCallToAPI('AbuseReportMarkComplete', false, array(
				'Number' => $abuseReport
			), array(
				'Finished' => array('boolean'=>array())
			));

			return $result->Finished;
		}

//!	Attempt to update the notes for the specified abuse report
/**
*	@param mixed $abuseReport Either an integer corresponding to Aurora::Addon::WebUI::AbuseReport::Number() or an instance of Aurora::Addon::WebUI::AbuseReport
*	@param string $notes Notes on the abuse report
*	@return boolean TRUE on success, FALSE on failure (usually because the specified abuse report doesn't exist).
*/
		public function AbuseReportSaveNotes($abuseReport, $notes){
			if($abuseReport instanceof WebUI\AbuseReport){
				$abuseReport = $abuseReport->Number();
			}else if(is_string($abuseReport) === true && ctype_digit($abuseReport) === true){
				$abuseReport = (integer)$abuseReport;
			}

			if(is_integer($abuseReport) === false){
				throw new InvalidArgumentException('Abuse report number must be specified as an integer.');
			}else if(is_string($notes) === false){
				throw new InvalidArgumentException('Abuse report notes must be specified as a string.');
			}

			$result = $this->makeCallToAPI('AbuseReportSaveNotes', false, array(
				'Number' => $abuseReport, 
				'Notes' => trim($notes)
			), array(
				'Finished' => array('boolean'=>array())
			));

			return $result->Finished;
		}

#endregion

#region Places

#region Estate

//!	Gets the array used as the expected response parameter for Aurora::Addon::WebUI::makeCallToAPI()
/**
*	@return array
*/
		private static function EstateSettingsValidator(){
			return array(
				'object' => array(array(
					'EstateID' => array('integer'=>array()),
					'EstateName' => array('string'=>array()),
					'AbuseEmailToEstateOwner' => array('boolean'=>array()),
					'DenyAnonymous' => array('boolean'=>array()),
					'ResetHomeOnTeleport' => array('boolean'=>array()),
					'FixedSun' => array('boolean'=>array()),
					'DenyTransacted' => array('boolean'=>array()),
					'BlockDwell' => array('boolean'=>array()),
					'DenyIdentified' => array('boolean'=>array()),
					'AllowVoice' => array('boolean'=>array()),
					'UseGlobalTime' => array('boolean'=>array()),
					'PricePerMeter' => array('integer'=>array()),
					'TaxFree' => array('boolean'=>array()),
					'AllowDirectTeleport' => array('boolean'=>array()),
					'RedirectGridX' => array('integer'=>array(), 'null'=>array()),
					'RedirectGridY' => array('integer'=>array(), 'null'=>array()),
					'ParentEstateID' => array('integer'=>array()),
					'SunPosition' => array('float'=>array()),
					'EstateSkipScripts' => array('boolean'=>array()),
					'BillableFactor' => array('float'=>array()),
					'PublicAccess' => array('boolean'=>array()),
					'AbuseEmail' => array('string'=>array()),
					'EstateOwner' => array('string'=>array()),
					'DenyMinors' => array('boolean'=>array()),
					'AllowLandmark' => array('boolean'=>array()),
					'AllowParcelChanges' => array('boolean'=>array()),
					'AllowSetHome' => array('boolean'=>array()),
					'EstateBans' => array('array'=>array(array('string'=>array()))),
					'EstateManagers' => array('array'=>array(array('string'=>array()))),
					'EstateGroups' => array('array'=>array(array('string'=>array()))),
					'EstateAccess' => array('array'=>array(array('string'=>array()))),
				))
			);
		}

//!	Converts an API result into an EstateSettings object
/**
*	@return object instance of EstateSettings
*/
		private static function EstateSettingsFromResult(\stdClass $Estate){
			return WebUI\EstateSettings::r(
				$Estate->EstateID,
				$Estate->EstateName,
				$Estate->AbuseEmailToEstateOwner,
				$Estate->DenyAnonymous,
				$Estate->ResetHomeOnTeleport,
				$Estate->FixedSun,
				$Estate->DenyTransacted,
				$Estate->BlockDwell,
				$Estate->DenyIdentified,
				$Estate->AllowVoice,
				$Estate->UseGlobalTime,
				$Estate->PricePerMeter,
				$Estate->TaxFree,
				$Estate->AllowDirectTeleport,
				$Estate->RedirectGridX,
				$Estate->RedirectGridY,
				$Estate->ParentEstateID,
				$Estate->SunPosition,
				$Estate->EstateSkipScripts,
				$Estate->BillableFactor,
				$Estate->PublicAccess,
				$Estate->AbuseEmail,
				$Estate->EstateOwner,
				$Estate->DenyMinors,
				$Estate->AllowLandmark,
				$Estate->AllowParcelChanges,
				$Estate->AllowSetHome,
				$Estate->EstateBans,
				$Estate->EstateManagers,
				$Estate->EstateGroups,
				$Estate->EstateAccess
			);
		}

//!	Gets all estates with the specified owner and optional boolean filters
/**
*	@param string $Owner Owner UUID
*	@param array $boolFields optional array of field names for keys and booleans for values, indicating 1 and 0 for field values.
*	@return object instance of Aurora::Addon::WebUI::EstateSettingsIterator
*/
		public function GetEstates($Owner, array $boolFields=null){
			if(($Owner instanceof WebUI\abstractUser) === false){
				if(is_string($Owner) === false){
					throw new InvalidArgumentException('OwnerID must be a string.');
				}else if(preg_match(self::regex_UUID, $Owner) !== 1){
					throw new InvalidArgumentException('OwnerID must be a valid UUID.');
				}
				$Owner = $this->GetProfile('', $Owner);
			}

			$input = array(
				'Owner' => $Owner->PrincipalID()
			);
			if(isset($boolFields) === true){
				$input['BoolFields'] = $boolFields;
			}

			$Estates = $this->makeCallToAPI('GetEstates', true, $input, array(
				'Estates' => array('array' => array(
					static::EstateSettingsValidator()
				))
			))->Estates;
			$result = array();
			foreach($Estates as $Estate){
				$result[] = static::EstateSettingsFromResult($Estate);
			}

			return new WebUI\EstateSettingsIterator($result);
		}

//!	Gets a single estate by estate name
/**
*	@param mixed $Estate Estate ID or Estate Name
*	@return object instance of Aurora::Addon::WebUI::EstateSettings
*/
		public function GetEstate($Estate){
			if(is_string($Estate) === true){
				if(ctype_digit($Estate) === true){
					$Estate = (integer)$Estate;
				}else{
					$Estate = trim($Estate);
				}
			}

			if(is_integer($Estate) === false && is_string($Estate) === false){
				throw new InvalidArgumentException('Estate must be specified as integer or string.');
			}

			return static::EstateSettingsFromResult($this->makeCallToAPI('GetEstate', true, array('Estate' => $Estate), array(
				'Estate' => static::EstateSettingsValidator()
			))->Estate);
		}

#endregion

#region Regions

//!	Gets the array used as the expected response parameter for Aurora::Addon::WebUI::makeCallToAPI()
/**
*	@return array
*/
		private static function GridRegionValidator(){
			return array('object' => array(array(
				'uuid'                 => array('string'  => array()),
				'locX'                 => array('integer' => array()),
				'locY'                 => array('integer' => array()),
				'locZ'                 => array('integer' => array()),
				'regionName'           => array('string'  => array()),
				'regionType'           => array('string'  => array()),
				'serverIP'             => array('string'  => array()),
				'serverHttpPort'       => array('integer' => array()),
				'serverURI'            => array('string'  => array()),
				'serverPort'           => array('integer' => array()),
				'regionMapTexture'     => array('string'  => array()),
				'regionTerrainTexture' => array('string'  => array()),
				'access'               => array('integer' => array()),
				'owner_uuid'           => array('string'  => array()),
				'AuthToken'            => array('string'  => array()),
				'sizeX'                => array('integer' => array()),
				'sizeY'                => array('integer' => array()),
				'sizeZ'                => array('integer' => array()),
				'LastSeen'             => array('integer' => array()),
				'SessionID'            => array('string'  => array()),
				'Flags'                => array('integer' => array()),
				'GenericMap'           => array('object'  => array()),
				'EstateOwner'          => array('string'  => array()),
				'EstateID'             => array('integer' => array()),
				'remoteEndPointIP'     => array('array'   => array()),
				'remoteEndPointPort'   => array('integer' => array()),
			)));
		}

//!	Get a list of regions in the AuroraSim install that match the specified flags.
/**
*	@param integer $flags A bitfield corresponding to constants in Aurora::Framework::RegionFlags
*	@param integer $excludeFlags exclusive region flags
*	@param integer $start start point. If Aurora::Addon::WebUI::GetRegions is primed, then Aurora::Addon::WebUI::GetRegions::r() will auto-seek to start.
*	@param mixed $count Either an integer for the maximum number of regions to fetch from the API end point in a single batch, or NULL to use the end point's default value.
*	@param mixed $sortRegionName flag for sorting by region name. NULL or boolean where TRUE indicates ascending sort and FALSE indicates descending sort.
*	@param mixed $sortLocX flag for sorting by x-axis coordinates NULL or boolean where TRUE indicates ascending sort and FALSE indicates descending sort.
*	@param mixed $sortLocY flag for sorting by y-axis coordinates NULL or boolean where TRUE indicates ascending sort and FALSE indicates descending sort.
*	@param boolean $asArray controls whether to return an iterator object or a raw result array.
*	@return mixed If $asArray is TRUE returns an array, otherwise returns an instance of Aurora::Addon::WebUI::GetRegions
*	@see Aurora::Addon::WebUI::makeCallToAPI()
*	@see Aurora::Addon::WebUI::fromEndPointResult()
*	@see Aurora::Addon::WebUI::GetRegions::r()
*/
		public function GetRegions($flags=null, $excludeFlags=null, $start=0, $count=10, $sortRegionName=null, $sortLocX=null, $sortLocY=null, $asArray=false){
			if(isset($flags) === false){
				$flags = RegionFlags::RegionOnline;
			}
			if(isset($excludeFlags) === false){
				$excludeFlags = 0;
			}
			if(is_string($flags) === true && ctype_digit($flags) === true){
				$flags = (integer)$flags;
			}
			if(is_string($excludeFlags) === true && ctype_digit($excludeFlags) === true){
				$excludeFlags = (integer)$excludeFlags;
			}
			if(is_string($start) === true && ctype_digit($start) === true){
				$start = (integer)$start;
			}
			if(is_string($count) === true && ctype_digit($count) === true){
				$count = (integer)$count;
			}

			if(is_bool($asArray) === false){
				throw new InvalidArgumentException('asArray flag must be a boolean.');
			}else if(is_integer($flags) === false){
				throw new InvalidArgumentException('RegionFlags argument should be supplied as integer.');
			}else if($flags < 0){
				throw new InvalidArgumentException('RegionFlags cannot be less than zero');
			}else if(RegionFlags::isValid($flags) === false){ // Aurora::Framework::RegionFlags::isValid() does do a check for integerness, but we want to throw a different exception message if it is an integer.
				throw new InvalidArgumentException('RegionFlags value is invalid, aborting call to API');
			}else if($excludeFlags < 0){
				throw new InvalidArgumentException('Region ExcludeFlags cannot be less than zero');
			}else if(RegionFlags::isValid($excludeFlags) === false){ // Aurora::Framework::RegionFlags::isValid() does do a check for integerness, but we want to throw a different exception message if it is an integer.
				throw new InvalidArgumentException('Region ExcludeFlags value is invalid, aborting call to API');
			}else if(is_integer($start) === false){
				throw new InvalidArgumentException('Start point must be an integer.');
			}else if(isset($count) === true){
				if(is_integer($count) === false){
					throw new InvalidArgumentException('Count must be an integer.');
				}else if($count < 1){
					throw new InvalidArgumentException('Count must be greater than zero.');
				}
			}else if(isset($sortRegionName) === true && is_bool($sortRegionName) === false){
				throw new InvalidArgumentException('If set, the sort by region name flag must be a boolean.');
			}else if(isset($sortLocX) === true && is_bool($sortLocX) === false){
				throw new InvalidArgumentException('If set, the sort by x-axis flag must be a boolean.');
			}else if(isset($sortLocY) === true && is_bool($sortLocY) === false){
				throw new InvalidArgumentException('If set, the sort by y-axis flag must be a boolean.');
			}
			$response = array();
			$input = array(
				'RegionFlags'        => $flags,
				'ExcludeRegionFlags' => $excludeFlags,
				'Start'              => $start,
				'Count'              => $count
			);
			if(isset($sortRegionName) === true){
				$input['SortRegionName'] = $sortRegionName;
			}
			if(isset($sortLocX) === true){
				$input['SortLocX'] = $sortLocX;
			}
			if(isset($sortLocY) === true){
				$input['SortLocY'] = $sortLocY;
			}
			$has = WebUI\GetRegions::hasInstance($this, $flags, $excludeFlags, $sortRegionName, $sortLocX, $sortLocY);
			if($asArray === true || $has === false){
				$result = $this->makeCallToAPI('GetRegions', true, $input, array(
					'Regions' => array('array'=>array(static::GridRegionValidator())),
					'Total'   => array('integer'=>array())
				));
				foreach($result->Regions as $val){
					$response[] = WebUI\GridRegion::fromEndPointResult($val);
				}
			}

			return $asArray ? $response : WebUI\GetRegions::r($this, $flags, $excludeFlags, $start, $has ? null : $result->Total, $sortRegionName, $sortLocX, $sortLocY, $response);
		}

//!	Get a list of regions in the AuroraSim install at the specified x/y coordinates that also match the specified flags.
/**
*	@param integer $x x-axis coordinates of region
*	@param integer $y y-axis coordinates of region
*	@param integer $flags inclusive region flags
*	@param integer $excludeFlags exclusive region flags
*	@param integer $scopeID region scope ID
*	@return object returns an instance of Aurora::Addon::WebUI::GetRegions, although may return a child class later
*/
		public function GetRegionsByXY($x, $y, $flags=null, $excludeFlags=null, $scopeID='00000000-0000-0000-0000-000000000000'){
			if(isset($flags) === false){
				$flags = RegionFlags::RegionOnline;
			}
			if(is_string($x) === true && ctype_digit($x) === true){
				$x = (integer)$x;
			}
			if(is_string($y) === true && ctype_digit($y) === true){
				$y = (integer)$y;
			}

			if(is_integer($flags) === false){
				throw new InvalidArgumentException('RegionFlags argument should be supplied as integer.');
			}else if($flags < 0){
				throw new InvalidArgumentException('RegionFlags cannot be less than zero');
			}else if(RegionFlags::isValid($flags) === false){ // Aurora::Framework::RegionFlags::isValid() does do a check for integerness, but we want to throw a different exception message if it is an integer.
				throw new InvalidArgumentException('RegionFlags value is invalid, aborting call to API');
			}else if(is_string($scopeID) === false){
				throw new InvalidArgumentException('ScopeID must be specified as a string.');
			}else if(preg_match(self::regex_UUID, $scopeID) != 1){
				throw new InvalidArgumentException('ScopeID must be a valid UUID.');
			}
			if(isset($excludeFlags) === true){
				if(is_integer($excludeFlags) === false){
					throw new InvalidArgumentException('RegionFlags exclusion argument should be supplied as integer.');
				}else if($excludeFlags < 0){
					throw new InvalidArgumentException('RegionFlags exclusion cannot be less than zero');
				}else if(RegionFlags::isValid($excludeFlags) === false){ // Aurora::Framework::RegionFlags::isValid() does do a check for integerness, but we want to throw a different exception message if it is an integer.
					throw new InvalidArgumentException('RegionFlags exclusion value is invalid, aborting call to API');
				}
			}

			$input = array(
				'X'           => $x,
				'Y'           => $y,
				'ScopeID'     => $scopeID,
				'RegionFlags' => $flags
			);
			if(isset($excludeFlags) === true){
				$input['ExcludeRegionFlags'] = $excludeFlags;
			}

			$result = $this->makeCallToAPI('GetRegionsByXY', true, $input, array(
				'Regions' => array('array'=>array(static::GridRegionValidator())),
				'Total'   => array('integer'=>array())
			));
			$response = array();
			foreach($result->Regions as $val){
				$response[] = WebUI\GridRegion::fromEndPointResult($val);
			}

			return WebUI\GetRegionsByXY::r($this, $x, $y, $flags, isset($excludeFlags) ? $excludeFlags : 0, $scopeID, $response);
		}

//!	Get a list of regions in the specified area
/**
*	@param integer $startX x-axis start point
*	@param integer $startY y-axis start point
*	@param integer $endX x-axis end point
*	@param integer $endY y-axis end point
*	@param string $scopeID The scope ID for regions to fetch
*	@param boolean $asArray controls whether to return an iterator object or a raw result array.
*/
		public function GetRegionsInArea($startX, $startY, $endX, $endY, $scopeID='00000000-0000-0000-0000-000000000000', $asArray=false){
			$has      = WebUI\GetRegionsInArea::hasInstance($this, $startX, $startY, $endX, $endY, $scopeID);
			$response = array();
			if($asArray === true || $has === false){
				$result = $this->makeCallToAPI('GetRegionsInArea', true, array(
					'StartX'  => $startX,
					'StartY'  => $startY,
					'EndX'    => $endX,
					'EndY'    => $endY,
					'ScopeID' => $scopeID
				), array(
					'Regions' => array('array'=>array(static::GridRegionValidator())),
					'Total'   => array('integer'=>array())
				));
				foreach($result->Regions as $val){
					$response[] = WebUI\GridRegion::fromEndPointResult($val);
				}
			}

			return $asArray ? $response : WebUI\GetRegionsInArea::r($this, $startX, $startY, $endX, $endY, $scopeID, 0, $result->Total, $response);
		}

//!	Get a list of regions in the specified estate that match the specified flags.
/**
*	@param object $Estate instance of Aurora::Addon::WebUI::EstateSettings
*	@param integer $flags A bitfield corresponding to constants in Aurora::Framework::RegionFlags
*	@param integer $start start point. If Aurora::Addon::WebUI::GetRegions is primed, then Aurora::Addon::WebUI::GetRegions::r() will auto-seek to start.
*	@param mixed $count Either an integer for the maximum number of regions to fetch from the API end point in a single batch, or NULL to use the end point's default value.
*	@param mixed $sortRegionName flag for sorting by region name. NULL or boolean where TRUE indicates ascending sort and FALSE indicates descending sort.
*	@param mixed $sortLocX flag for sorting by x-axis coordinates NULL or boolean where TRUE indicates ascending sort and FALSE indicates descending sort.
*	@param mixed $sortLocY flag for sorting by y-axis coordinates NULL or boolean where TRUE indicates ascending sort and FALSE indicates descending sort.
*	@param boolean $asArray controls whether to return an iterator object or a raw result array.
*	@return mixed If $asArray is TRUE returns an array, otherwise returns an instance of Aurora::Addon::WebUI::GetRegionsInEstate
*	@see Aurora::Addon::WebUI::makeCallToAPI()
*	@see Aurora::Addon::WebUI::fromEndPointResult()
*	@see Aurora::Addon::WebUI::GetRegions::r()
*/
		public function GetRegionsInEstate(WebUI\EstateSettings $Estate, $flags=null, $start=0, $count=null, $sortRegionName=null, $sortLocX=null, $sortLocY=null, $asArray=false){
			if(isset($flags) === false){
				$flags = RegionFlags::RegionOnline;
			}
			if(is_bool($asArray) === false){
				throw new InvalidArgumentException('asArray flag must be a boolean.');
			}else if(is_integer($flags) === false){
				throw new InvalidArgumentException('RegionFlags argument should be supplied as integer.');
			}else if($flags < 0){
				throw new InvalidArgumentException('RegionFlags cannot be less than zero');
			}else if(RegionFlags::isValid($flags) === false){ // Aurora::Framework::RegionFlags::isValid() does do a check for integerness, but we want to throw a different exception message if it is an integer.
				throw new InvalidArgumentException('RegionFlags value is invalid, aborting call to API');
			}else if(is_integer($start) === false){
				throw new InvalidArgumentException('Start point must be an integer.');
			}else if(isset($count) === true){
				if(is_integer($count) === false){
					throw new InvalidArgumentException('Count must be an integer.');
				}else if($count < 1){
					throw new InvalidArgumentException('Count must be greater than zero.');
				}
			}else if(isset($sortRegionName) === true && is_bool($sortRegionName) === false){
				throw new InvalidArgumentException('If set, the sort by region name flag must be a boolean.');
			}else if(isset($sortLocX) === true && is_bool($sortLocX) === false){
				throw new InvalidArgumentException('If set, the sort by x-axis flag must be a boolean.');
			}else if(isset($sortLocY) === true && is_bool($sortLocY) === false){
				throw new InvalidArgumentException('If set, the sort by y-axis flag must be a boolean.');
			}
			$response = array();
			$input = array(
				'Estate'      => $Estate->EstateID(),
				'RegionFlags' => $flags,
				'Start'       => $start,
				'Count'       => $count
			);

			if(isset($sortRegionName) === true || isset($sortRegionName) === true || isset($sortLocY) === true){
				$input['Sort'] = array();
				if(isset($sortRegionName) === true){
					$input['Sort']['RegionName'] = $sortRegionName;
				}
				if(isset($sortLocX) === true){
					$input['Sort']['LocX'] = $sortLocX;
				}
				if(isset($sortLocY) === true){
					$input['Sort']['LocY'] = $sortLocY;
				}
			}

			$has = WebUI\GetRegions::hasInstance($this, $Estate, $flags, $sortRegionName, $sortLocX, $sortLocY);
			if($asArray === true || $has === false){
				$result = $this->makeCallToAPI('GetRegions', true, $input, array(
					'Regions' => array('array'=>array(static::GridRegionValidator())),
					'Total'   => array('integer'=>array())
				));
				foreach($result->Regions as $val){
					$response[] = WebUI\GridRegion::fromEndPointResult($val);
				}
			}

			return $asArray ? $response : WebUI\GetRegionsInEstate::r($this, $Estate, $flags, 0, $start, $has ? null : $result->Total, $sortRegionName, $sortLocX, $sortLocY, $response);
		}

//!	Get a single region
/**
*	@param string $region either a UUID or a region name.
*	@param string $scopeID region scope ID
*	@return object instance of Aurora::Addon::WebUI::GridRegion
*/
		public function GetRegion($region, $scopeID='00000000-0000-0000-0000-000000000000'){
			if(is_string($region) === false){
				throw new InvalidArgumentException('Region must be specified as a string.');
			}else if(trim($region) === ''){
				throw new InvalidArgumentException('Region must not be an empty string.');
			}else if(is_string($scopeID) === false){
				throw new InvalidArgumentException('ScopeID must be specified as a string.');
			}else if(preg_match(self::regex_UUID, $scopeID) != 1){
				throw new InvalidArgumentException('ScopeID must be a valid UUID.');
			}

			$input = array(
				'ScopeID' => $scopeID
			);

			if(preg_match(self::regex_UUID, $region) != 1){
				$input['Region'] = trim($region);
			}else{
				$input['RegionID'] = $region;
			}

			return WebUI\GridRegion::fromEndPointResult($this->makeCallToAPI('GetRegion', true, $input, array(
				'Region' => static::GridRegionValidator()
			))->Region);
		}

//!	Get a list of regions within range of the specified region
/**
*	@param string $region UUID of region
*	@param integer $range distance in meters from region center to search
*	@param string $scopeID Scope ID of region
*	@param integer $start start point for results
*	@param boolean $asArray controls whether to return an iterator object or a raw result array.
*	@return object returns an instance of Aurora::Addon::WebUI::GetRegionNeighbours
*/
		public function GetRegionNeighbours($region, $range=128, $scopeID='00000000-0000-0000-0000-000000000000', $start=0, $asArray=false){
			if(is_string($range) === true && ctype_digit($range) === true){
				$range = (integer)$range;
			}

			if(is_string($region) === false){
				throw new InvalidArgumentException('Region ID must be specified as a string.');
			}else if(preg_match(self::regex_UUID, $region) != 1){
				throw new InvalidArgumentException('Region ID must be a valid UUID.');
			}else if(is_string($scopeID) === false){
				throw new InvalidArgumentException('ScopeID must be specified as a string.');
			}else if(preg_match(self::regex_UUID, $scopeID) != 1){
				throw new InvalidArgumentException('ScopeID must be a valid UUID.');
			}else if(is_integer($range) === false){
				throw new InvalidArgumentException('Range must be specified as integer.');
			}else if($range < 8){
				throw new InvalidArgumentException('Range must be greater than or equal to 8');
			}

			$response = array();
			$has = WebUI\GetRegionNeighbours::hasInstance($this, $region, $range=128, $scopeID='00000000-0000-0000-0000-000000000000');
			if($asArray === true || $has === false){
				$result = $this->makeCallToAPI('GetRegionNeighbours', true, array(
						'RegionID' => $region,
						'ScopeID'  => $scopeID,
						'Range'    => $range
					), array(
						'Regions' => array('array'=>array(static::GridRegionValidator())),
						'Total'   => array('integer'=>array())
					)
				);
				foreach($result->Regions as $val){
					$response[] = WebUI\GridRegion::fromEndPointResult($val);
				}
			}
			return $asArray ? $response : WebUI\GetRegionNeighbours::r($this, $region, $range=128, $scopeID='00000000-0000-0000-0000-000000000000', $start, $has ? null : $result->Total, $response);
		}

#endregion

#region Parcels

//!	PHP doesn't do const arrays :(
/**
*	@return array The validator array to be passed to Aurora::Addon::WebUI::makeCallToAPI() when making parcel-related calls.
*/
		protected static function ParcelResultValidatorArray(){
			static $validator = array(
				'object' => array(array(
					'GroupID' => array('string' => array()),
					'OwnerID' => array('string' => array()),
					'Maturity' => array('integer' => array()),
					'Area' => array('integer' => array()),
					'AuctionID' => array('integer' => array()),
					'SalePrice' => array('integer' => array()),
					'InfoUUID' => array('string' => array()),
					'Dwell' => array('integer' => array()),
					'Flags' => array('integer' => array()),
					'Name' => array('string' => array()),
					'Description' => array('string' => array()),
					'UserLocation' => array('array' => array(array(
						array('float' => array()),
						array('float' => array()),
						array('float' => array())
					))),
					'LocalID' => array('integer' => array()),
					'GlobalID' => array('string' => array()),
					'RegionID' => array('string' => array()),
					'MediaDescription' => array('string' => array()),
					'MediaHeight' => array('integer' => array()),
					'MediaLoop' => array('boolean' => array()),
					'MediaType' => array('string' => array()),
					'ObscureMedia' => array('boolean' => array()),
					'ObscureMusic' => array('boolean' => array()),
					'SnapshotID' => array('string' => array()),
					'MediaAutoScale' => array('integer' => array()),
					'MediaLoopSet' => array('float' => array()),
					'MediaURL' => array('string' => array()),
					'MusicURL' => array('string' => array()),
					'Bitmap' => array('string' => array()),
					'Category' => array('integer' => array()),
					'FirstParty' => array('boolean' => array()),
					'ClaimDate' => array('integer' => array()),
					'ClaimPrice' => array('integer' => array()),
					'Status' => array('integer' => array()),
					'LandingType' => array('integer' => array()),
					'PassHours' => array('float' => array()),
					'PassPrice' => array('integer' => array()),
					'UserLookAt' => array('array' => array(array(
						array('float' => array()),
						array('float' => array()),
						array('float' => array())
					))),
					'AuthBuyerID' => array('string' => array()),
					'OtherCleanTime' => array('integer' => array()),
					'RegionHandle' => array('string' => array()),
					'Private' => array('boolean' => array()),
					'GenericData' => array('object' => array()),
				))
			);
			return $validator;
		}

//!	Converts an API result for parcels to an instance of Aurora::Addon::WebUI::LandData
/**
*	@param object $result API result
*	@return object instance of Aurora::Addon::WebUI::LandData
*/
		private static function ParcelResult2LandData(\stdClass $result){
			$result->UserLookAt   = new Vector3($result->UserLookAt[0]  , $result->UserLookAt[1]  , $result->UserLookAt[2]  );
			$result->UserLocation = new Vector3($result->UserLocation[0], $result->UserLocation[1], $result->UserLocation[2]);
			return WebUI\LandData::r(
				$result->InfoUUID,
				$result->RegionID,
				$result->GlobalID,
				$result->LocalID,
				$result->SalePrice,
				$result->UserLocation,
				$result->UserLookAt,
				$result->Name,
				$result->Description,
				$result->Flags,
				$result->Dwell,
				$result->AuctionID,
				$result->Area,
				$result->Maturity,
				$result->OwnerID,
				$result->GroupID,
				$result->IsGroupOwned,
				$result->SnapshotID,
				$result->MediaDescription,
				$result->MediaWidth,
				$result->MediaHeight,
				$result->MediaLoop,
				$result->MediaType,
				$result->ObscureMedia,
				$result->ObscureMusic,
				$result->MediaLoopSet,
				$result->MediaAutoScale,
				$result->MediaURL,
				$result->MusicURL,
				$result->Bitmap,
				$result->Category,
				$result->FirstParty,
				$result->ClaimDate,
				$result->ClaimPrice,
				$result->LandingType,
				$result->PassHours,
				$result->PassPrice,
				$result->AuthBuyerID,
				$result->OtherCleanTime,
				$result->RegionHandle,
				$result->Private,
				$result->GenericData
			);
		}

//!	Gets all parcels in a region, optionally filtering by parcel owner and region scope ID
/**
*	@param integer $start start point for results (useful for paginated output)
*	@param integer $count maximum number of results to return in initial call.
*	@param object $region instance of Aurora::Addon::WebUI::GridRegion
*	@param string $owner Parcel owner UUID
*	@param string $scopeID Region scope ID
*	@param boolean $asArray if TRUE return array of parcels, if FALSE return Iterator object
*	@return mixed Either an array of Aurora::Addon::WebUI::LandData or an instance of Aurora::Addon::WebUI::GetParcelsByRegion
*/
		public function GetParcelsByRegion($start=0, $count=10, WebUI\GridRegion $region, $owner='00000000-0000-0000-0000-000000000000', $scopeID='00000000-0000-0000-0000-000000000000', $asArray=false){
			if(is_string($start) === true && ctype_digit($start) === true){
				$start = (integer)$start;
			}
			if(is_string($count) === true && ctype_digit($count) === true){
				$count = (integer)$count;
			}

			if(is_integer($start) === false){
				throw new InvalidArgumentException('Start point must be specified as integer.');
			}else if($start < 0){
				throw new InvalidArgumentException('Start point must be greater than or equal to zero.');
			}else if(is_integer($count) === false){
				throw new InvalidArgumentException('Count must be specified as integer.');
			}else if($count < 0){
				throw new InvalidArgumentException('Count must be greater than or equal to zero.');
			}else if(is_string($owner) === false){
				throw new InvalidArgumentException('Owner must be specified as string.');
			}else if(preg_match(self::regex_UUID, $owner) != 1){
				throw new InvalidArgumentException('Owner must be valid UUID.');
			}else if(is_string($scopeID) === false){
				throw new InvalidArgumentException('scopeID must be specified as string.');
			}else if(preg_match(self::regex_UUID, $scopeID) != 1){
				throw new InvalidArgumentException('scopeID must be valid UUID.');
			}

			$result = $this->makeCallToAPI('GetParcelsByRegion', true, array(
				'Start'   => $start,
				'Count'   => $count,
				'Region'  => $region->RegionID(),
				'Owner'   => $owner,
				'ScopeID' => $scopeID,
			), array(
				'Parcels' => array(
					'array' => array(self::ParcelResultValidatorArray())
				),
				'Total' => array('integer'=>array())
			));
			foreach($result->Parcels as $k=>$v){
				$result->Parcels[$k] = self::ParcelResult2LandData($v);
			}
			return $asArray ? $result->Parcels : WebUI\GetParcelsByRegion::r($this, $start, $result->Total, $region, $owner, $scopeID, $result->Parcels);
		}

//!	Gets a parcel either by infoID or by name, region and region scopeID
/**
*	@param string $parcel Either a parcel's infoID or a parcel name
*	@param mixed $region Either NULL when $parcel is a UUID, or an instance of Aurora::Addon::WebUI::GridRegion
*	@param string $scopeID Region ScopeID
*	@return object instance of Aurora::Addon::WebUI::LandData
*/
		public function GetParcel($parcel, WebUI\GridRegion $region=null, $scopeID='00000000-0000-0000-0000-000000000000'){
			if(is_string($parcel) === false){
				throw new InvalidArgumentException('Parcel argument must be specified as string.');
			}else if(is_string($scopeID) === false){
				throw new InvalidArgumentException('ScopeID must be specified as string.');
			}else if(preg_match(self::regex_UUID, $scopeID) != 1){
				throw new InvalidArgumentException('ScopeID must be a valid UUID.');
			}

			$input = array();
			if(preg_match(self::regex_UUID, $parcel) != 1){
				if(isset($region) === false){
					throw new InvalidArgumentException('When attempting to get a parcel by name, the region must be specified.');
				}
				$input['RegionID'] = $region->RegionID();
				$input['ScopeID'] = $scopeID;
				$input['Parcel'] = trim($parcel);
			}else{
				$input['ParcelInfoUUID'] = $parcel;
			}

			return self::ParcelResult2LandData($this->makeCallToAPI('GetParcel', true, $input, array(
				'Parcel' => self::ParcelResultValidatorArray()
			))->Parcel);
		}

//!	Gets all parcels in the specified region with the specified parcel name.
/**
*	@param integer $start start point for results (useful for paginated output)
*	@param integer $count maximum number of results to return in initial call.
*	@param string $name Parcel name
*	@param object $region instance of Aurora::Addon::WebUI::GridRegion
*	@param string $scopeID Region scope ID
*	@param boolean $asArray if TRUE return array of parcels, if FALSE return Iterator object
*	@return mixed Either an array of Aurora::Addon::WebUI::LandData or an instance of Aurora::Addon::WebUI::GetParcelsWithNameByRegion
*/
		public function GetParcelsWithNameByRegion($start=0, $count=10, $name, WebUI\GridRegion $region, $scopeID='00000000-0000-0000-0000-000000000000', $asArray=false){
			if(is_string($start) === true && ctype_digit($start) === true){
				$start = (integer)$start;
			}
			if(is_string($count) === true && ctype_digit($count) === true){
				$count = (integer)$count;
			}
			if(is_string($name) === true){
				$name = trim($name);
			}

			if(is_integer($start) === false){
				throw new InvalidArgumentException('Start point must be specified as integer.');
			}else if($start < 0){
				throw new InvalidArgumentException('Start point must be greater than or equal to zero.');
			}else if(is_integer($count) === false){
				throw new InvalidArgumentException('Count must be specified as integer.');
			}else if($count < 0){
				throw new InvalidArgumentException('Count must be greater than or equal to zero.');
			}else if(is_string($scopeID) === false){
				throw new InvalidArgumentException('scopeID must be specified as string.');
			}else if(preg_match(self::regex_UUID, $scopeID) != 1){
				throw new InvalidArgumentException('scopeID must be valid UUID.');
			}else if(is_string($name) === false){
				throw new InvalidArgumentException('Parcel name must be specified as string.');
			}else if($name === ''){
				throw new InvalidArgumentException('Parcel name must not be empty string.');
			}

			$result = $this->makeCallToAPI('GetParcelsWithNameByRegion', true, array(
				'Start'   => $start,
				'Count'   => $count,
				'Region'  => $region->RegionID(),
				'Parcel'   => $name,
				'ScopeID' => $scopeID,
			), array(
				'Parcels' => array(
					'array' => array(self::ParcelResultValidatorArray())
				),
				'Total' => array('integer'=>array())
			));
			foreach($result->Parcels as $k=>$v){
				$result->Parcels[$k] = self::ParcelResult2LandData($v);
			}
			return $asArray ? $result->Parcels : WebUI\GetParcelsWithNameByRegion::r($this, $start, $result->Total, $name, $region, $scopeID, $result->Parcels);
		}

#endregion

#endregion

#region Groups

#region GroupRecord

//!	Converts an instances of stdClass from Aurora::Addon::WebUI::GetGroups() and Aurora::Addon::WebUI::GetGroup() results to an instance of Aurora::Addon::WebUI::GroupRecord
/**
*	@param object $group instance of stdClass with group properties
*	@return object corresponding instance of Aurora::Addon::WebUI::GroupRecord
*/
		private static function GroupResult2GroupRecord(\stdClass $group){
			if(isset(
				$group->GroupID,
				$group->GroupName,
				$group->Charter,
				$group->GroupPicture,
				$group->FounderID,
				$group->MembershipFee,
				$group->OpenEnrollment,
				$group->ShowInList,
				$group->AllowPublish,
				$group->MaturePublish,
				$group->OwnerRoleID
			) === false){
				throw new UnexpectedValueException('Call to API was successful, but required response sub-properties were missing.');
			}
			return WebUI\GroupRecord::r(
				$group->GroupID,
				$group->GroupName,
				$group->Charter,
				$group->GroupPicture,
				$group->FounderID,
				$group->MembershipFee,
				$group->OpenEnrollment,
				$group->ShowInList,
				$group->AllowPublish,
				$group->MaturePublish,
				$group->OwnerRoleID
			);
		}

//!	Enables or disables the specified group as a news source for WebUI
/**
*	Throws an exception on failure, for laziness :P
*	@param object $group instance of Aurora::Addon::WebUI::GroupRecord
*	@param boolean $useAsNewsSource TRUE to enable, FALSE to disable
*	@return boolean TRUE if successful, FALSE otherwise
*/
		public function GroupAsNewsSource(WebUI\GroupRecord $group, $useAsNewsSource=true){
			if(is_bool($useAsNewsSource) === false){
				throw new InvalidArgumentException('flag must be a boolean.');
			}

			return $this->makeCallToAPI('GroupAsNewsSource', true, array(
				'Group' => $group->GroupID(),
				'Use'   => $useAsNewsSource
			), array(
				'Verified' => array('boolean'=>array(true))
			))->Verified;
		}

//!	Gets an iterator for the number of groups specified, with optional filters.
/**
*	@param integer $start start point of iterator. negatives are supported (kinda).
*	@param integer $count Maximum number of groups to fetch from the WebUI API end-point.
*	@param array $sort optional array of field names for keys and booleans for values, indicating ASC and DESC sort orders for the specified fields.
*	@param array $boolFields optional array of field names for keys and booleans for values, indicating 1 and 0 for field values.
*	@return object Aurora::Addon::WebUI::GetGroupRecords
*	@see Aurora::Addon::WebUI::GetGroupRecords::r()
*/
		public function GetGroups($start=0, $count=10, array $sort=null, array $boolFields=null){
			$input = array(
				'Start' => $start,
				'Count' => $count
			);
			if(isset($sort) === true){
				$input['Sort'] = $sort;
			}
			if(isset($boolFields) === true){
				$input['BoolFields'] = $boolFields;
			}

			$result = $this->makeCallToAPI('GetGroups', true, $input, array(
				'Start'  => array('integer'=>array()),
				'Total'  => array('integer'=>array()),
				'Groups' => array('array'=>array(array('object'=>array()))),
			));

			$groups = array();
			foreach($result->Groups as $group){
				$groups[] = self::GroupResult2GroupRecord($group);
			}

			return WebUI\GetGroupRecords::r($this, $result->Start, $result->Total, $sort, $boolFields, $groups);
		}

//!	Gets an iterator for the groups usable as news sources.
/**
*	@param integer $start start point
*	@param integer $count Maximum number of groups to fetch from the WebUI API end-point
*	@param boolean $asArray if TRUE will return results as an array, otherwise will return an instance of Aurora::Addon::WebUI::GetNewsSources
*	@return mixed either an array of Aurora::Addon::WebUI::GroupRecord or an instance of Aurora::Addon::WebUI::GetNewsSources
*/
		public function GetNewsSources($start=0, $count=10, $asArray=false){
			if(is_string($start) === true && ctype_digit($start) === true){
				$start = (integer)$start;
			}
			if(is_string($count) === true && ctype_digit($count) === true){
				$count = (integer)$count;
			}

			if(is_integer($start) === false){
				throw new InvalidArgumentException('Start point must be specified as integer.');
			}else if($start < 0){
				throw new InvalidArgumentException('Start point must be greater than or equal to zero.');
			}else if(is_integer($count) === false){
				throw new InvalidArgumentException('Count must be specified as integer.');
			}else if($count < 1){
				throw new InvalidArgumentException('Count must be greater than or equal to one.');
			}else if(is_bool($asArray) === false){
				throw new InvalidArgumentException('asArray flag must be specified as boolean.');
			}

			$response = array();
			if($asArray === true || WebUI\GetNewsSources::hasInstance($this) === false){
				$result = $this->makeCallToAPI('GetNewsSources', true, array(
					'Start' => $start,
					'Count' => $count
				), array(
					'Total' => array('integer'=>array()),
					'Groups' => array('array'=>array(array('object'=>array())))
				));
				foreach($result->Groups as $group){
					$response[] = self::GroupResult2GroupRecord($group);
				}
			}

			return $asArray ? $response : WebUI\GetNewsSources::r($this, $start, $result->Total, $response);
		}

//!	Fetches the specified group
/**
*	@param string $nameOrUUID Either a group UUID, or a group name.
*	@return mixed either FALSE indicating no group was found, or an instance of Aurora::Addon::WebUI::GroupRecord
*	@see Aurora::Addon::WebUI::GroupRecord::r()
*/
		public function GetGroup($nameOrUUID){
			if(is_string($nameOrUUID) === true){
				$nameOrUUID = trim($nameOrUUID);
			}else if(is_string($nameOrUUID) === false){
				throw new InvalidArgumentException('Method argument should be a string.');
			}
			$name = '';
			$uuid = '00000000-0000-0000-0000-000000000000';
			if(preg_match(self::regex_UUID, $nameOrUUID) !== 1){
				$input = array(
					'Name' => $nameOrUUID
				);
			}else{
				$input = array(
					'UUID' => $nameOrUUID
				);
			}

			$result = $this->makeCallToAPI('GetGroup', true, $input, array(
				'Group' => array(
					'object'  => array(),
					'boolean' => array(false)
				)
			));

			return $result->Group ? self::GroupResult2GroupRecord($result->Group) : false;
		}

//!	Gets an iterator for the specified list of GroupIDs
/**
*	@param array $GroupIDs list of GroupIDs
*	@return object Aurora::Addon::WebUI::foreknowledgeGetGroupRecords
*/
		public function foreknowledgeGetGroupRecords(array $GroupIDs){

			$result = $this->makeCallToAPI('GetGroups', true, array(
				'Groups' => $GroupIDs
			), array(
				'Groups' => array('array'=>array(array('object'=>array()))),
			));

			$groups = array();
			foreach($result->Groups as $group){
				$groups[] = self::GroupResult2GroupRecord($group);
			}

			return WebUI\foreknowledgeGetGroupRecords::r($this, $result->Start, $result->Total, null, null, $groups);
		}

#endregion

#region GroupNoticeData

//!	PHP doesn't do const arrays :(
/**
*	@return array The validator array to be passed to Aurora::Addon::WebUI::makeCallToAPI() when making group notice-related calls.
*/
		protected static function GroupNoticeValidatorArray(){
			static $validator = array('object'=>array(array(
				'GroupID'       => array('string'=>array()),
				'NoticeID'      => array('string'=>array()),
				'Timestamp'     => array('integer'=>array()),
				'FromName'      => array('string'=>array()),
				'Subject'       => array('string'=>array()),
				'Message'       => array('string'=>array()),
				'HasAttachment' => array('boolean'=>array()),
				'ItemID'        => array('string'=>array()),
				'AssetType'     => array('integer'=>array()),
				'ItemName'      => array('string'=>array())
			)));
			return $validator;
		}

//!	Get group notices for the specified groups
/**
*	@param integer $start start point of iterator. negatives are supported (kinda).
*	@param integer $count Maximum number of group notices to fetch from the WebUI API end-point.
*	@param array $groups instances of GroupRecord
*	@param boolean $asArray controls whether to return an iterator object or a raw result array.
*	@return object instance of Aurora::Addon::WebUI::GetGroupNotices
*/
		public function GroupNotices($start=0, $count=10, array $groups, $asArray=false){
			$groupIDs = array();
			foreach($groups as $group){
				if($group instanceof WebUI\GroupRecord){
					$groupIDs[] = $group->GroupID();
				}else if(is_bool($group) === false){
					throw new InvalidArgumentException('Groups must be an array of Aurora::Addon::WebUI::GroupRecord instances');
				}
			}

			$result = $this->makeCallToAPI('GroupNotices', true, array(
				'Start' => $start,
				'Count' => $count,
				'Groups' => $groupIDs
			), array(
				'Total' => array('integer'=>array()),
				'GroupNotices' => array('array'=>array(self::GroupNoticeValidatorArray()))
			));

			$groupNotices = array();
			foreach($result->GroupNotices as $groupNotice){
				$groupNotices[] = WebUI\GroupNoticeData::r(
					$groupNotice->GroupID,
					$groupNotice->NoticeID,
					$groupNotice->Timestamp,
					$groupNotice->FromName,
					$groupNotice->Subject,
					$groupNotice->Message,
					$groupNotice->HasAttachment,
					$groupNotice->ItemID,
					$groupNotice->AssetType,
					$groupNotice->ItemName
				);
			}

			return $asArray ? $groupNotices : WebUI\GetGroupNotices::r($this, $start, $result->Total, $groupIDs, $groupNotices);
		}

//!	Get group notices from groups flagged as being news sources.
/**
*	@param integer $start start point of iterator. negatives are supported (kinda).
*	@param integer $count Maximum number of group notices to fetch from the WebUI API end-point.
*	@param boolean $asArray controls whether to return an iterator object or a raw result array.
*	@return mixed if $asArray is FALSE instance of Aurora::Addon::WebUI::GetGroupNotices, otherwise returns the raw result array
*/
		public function NewsFromGroupNotices($start=0, $count=10, $asArray=false){

			$result = $this->makeCallToAPI('NewsFromGroupNotices', true, array(
				'Start' => $start,
				'Count' => $count
			), array(
				'Total' => array('integer'=>array()),
				'GroupNotices' => array('array'=>array(self::GroupNoticeValidatorArray()))
			));

			$groupNotices = array();
			foreach($result->GroupNotices as $groupNotice){
				$groupNotices[] = WebUI\GroupNoticeData::r(
					$groupNotice->GroupID,
					$groupNotice->NoticeID,
					$groupNotice->Timestamp,
					$groupNotice->FromName,
					$groupNotice->Subject,
					$groupNotice->Message,
					$groupNotice->HasAttachment,
					$groupNotice->ItemID,
					$groupNotice->AssetType,
					$groupNotice->ItemName
				);
			}

			return $asArray ? $groupNotices : WebUI\GetNewsFromGroupNotices::r($this, $start, $result->Total, array(), $groupNotices);
		}

//!	Get individual group notice
/**
*	@param string $uuid UUID of the group notice you wish to fetch
*	@return object Instance of Aurora::Addon::WebUI::GroupNoticeData
*/
		public function GetGroupNotice($uuid){
			if(is_string($uuid) === false){
				throw new InvalidArgumentException('Group notice ID should be specified as string.');
			}else if(preg_match(self::regex_UUID, $uuid) !== 1){
				throw new InvalidArgumentException('Group notice ID should be a valid UUID');
			}
			$groupNotice = $this->makeCallToAPI('GetGroupNotice', true, array(
				'NoticeID' => strtolower($uuid)
			), array(
				'GroupNotice' => self::GroupNoticeValidatorArray()
			))->GroupNotice;

			return WebUI\GroupNoticeData::r(
				$groupNotice->GroupID,
				$groupNotice->NoticeID,
				$groupNotice->Timestamp,
				$groupNotice->FromName,
				$groupNotice->Subject,
				$groupNotice->Message,
				$groupNotice->HasAttachment,
				$groupNotice->ItemID,
				$groupNotice->AssetType,
				$groupNotice->ItemName
			);
		}

//!	Edit a group notice
/**
*	@param mixed $notice Either the UUID of a group notice, or an instance of Aurora::Addon::WebUI::GroupNoticeData
*	@param mixed $subject new subject string or null to indicate no change
*	@param mixed $message new message string or null to indicate no change
*/
		public function EditGroupNotice($notice, $subject=null, $message=null){
			if(isset($subject) === true && is_string($subject) === false){
				throw new InvalidArgumentException('If subject is specified, it must be specified as string.');
			}else if(isset($subject) === true && is_string($subject) === true && trim($subject) === ''){
				throw new InvalidArgumentException('If subject is specified, it must not be empty.');
			}else if(isset($message) === true && is_string($message) === false){
				throw new InvalidArgumentException('If message is specified, it must be specified as string.');
			}else if(isset($message) === true && is_string($message) === true && trim($message) === ''){
				throw new InvalidArgumentException('If message is specified, it must not be empty.');
			}else if(isset($subject, $message) === false){
				return true; // if no changes are made, return immediately
			}

			if($notice instanceof WebUI\GroupNoticeData){
				$notice = $notice->NoticeID();
			}
			if(is_string($notice) === false){
				throw new InvalidArgumentException('NoticeID must be specified as string.');
			}else if(preg_match(static::regex_UUID, $notice) != 1){
				throw new InvalidArgumentException('NoticeID must be a valid UUID.');
			}

			$input = array(
				'NoticeID' => $notice
			);
			if(isset($subject) === true){
				$input['Subject'] = trim($subject);
			}
			if(isset($message) === true){
				$input['Message'] = trim($message);
			}

			return $this->makeCallToAPI('EditGroupNotice', false, $input, array('Success' => array('boolean'=>array())))->Success;
		}

//!	Create a group notice.
/**
*	@param mixed $group Either the UUID of a group, or an instance of Aurora::Addon::WebUI::GroupRecord
*	@param mixed $author Either the UUID of a user, or an instance of Aurora::Services::Interfaces::User
*	@param string $subject notice subject
*	@param string $message notice message
*	@return string new notice ID
*/
		public function AddGroupNotice($group, $author, $subject, $message){
			if($group instanceof WebUI\GroupRecord){
				$group = $group->GroupID();
			}
			if($author instanceof \Aurora\Services\Interfaces\User){
				$author = $author->PrincipalID();
			}
			if(is_string($subject) === true){
				$subject = trim($subject);
			}
			if(is_string($message) === true){
				$message = trim($message);
			}

			if(is_string($group) === false){
				throw new InvalidArgumentException('Group ID must be specified as string.');
			}else if(preg_match(static::regex_UUID, $group) != 1){
				throw new InvalidArgumentException('Group ID must be specified as UUID.');
			}else if(is_string($author) === false){
				throw new InvalidArgumentException('Author ID must be specified as string.');
			}else if(preg_match(static::regex_UUID, $author) != 1){
				throw new InvalidArgumentException('Author ID must be specified as UUID.');
			}else if(is_string($subject) === false){
				throw new InvalidArgumentException('Subject must be specified as string.');
			}else if($subject === ''){
				throw new InvalidArgumentException('Subject must be specified as non-empty string.');
			}else if(is_string($message) === false){
				throw new InvalidArgumentException('Message must be specified as string.');
			}else if($message === ''){
				throw new InvalidArgumentException('Message must be specified as non-empty string.');
			}

			return $this->makeCallToAPI('AddGroupNotice', false, array(
				'GroupID'  => $group,
				'AuthorID' => $author,
				'Subject'  => $subject,
				'Message'  => $message
			), array('NoticeID'=>array('string'=>array())))->NoticeID;
		}

//!	Remove a group notice
/**
*	@param mixed $group Either the UUID of a group, or an instance of Aurora::Addon::WebUI::GroupRecord
*	@param string $notice The UUID of the group notice
*	@return bool TRUE if the notice was successfully deleted, FALSE otherwise
*/
		public function RemoveGroupNotice($group, $notice){
			if($group instanceof WebUI\GroupRecord){
				$group = $group->GroupID();
			}

			if(is_string($group) === false){
				throw new InvalidArgumentException('Group ID must be specified as string.');
			}else if(preg_match(static::regex_UUID, $group) != 1){
				throw new InvalidArgumentException('Group ID must be specified as UUID.');
			}else if(is_string($notice) === false){
				throw new InvalidArgumentException('NoticeID must be specified as string.');
			}else if(preg_match(static::regex_UUID, $notice) != 1){
				throw new InvalidArgumentException('NoticeID must be a valid UUID.');
			}

			return $this->makeCallToAPI('RemoveGroupNotice', false, array(
				'GroupID'  => $group,
				'NoticeID' => $notice
			), array(
				'Success' => array('boolean'=>array())
			))->Success;
		}

#endregion

#endregion

#region Events

//!	PHP doesn't do const arrays :(
/**
*	@return array The validator array to be passed to Aurora::Addon::WebUI::makeCallToAPI() when making event-related calls.
*/
		private static function EventsResultValidatorArray(){
			return array('object' => array(array(
				'eventID'     => array('integer' => array()),
				'creator'     => array('string'  => array()),
				'name'        => array('string'  => array()),
				'category'    => array('string'  => array()),
				'description' => array('string'  => array()),
				'date'        => array('string'  => array()),
				'dateUTC'     => array('integer' => array()),
				'duration'    => array('integer' => array()),
				'cover'       => array('integer' => array()),
				'amount'      => array('integer' => array()),
				'simName'     => array('string' => array()),
				'globalPos'   => array('array'   => array()),
				'eventFlags'  => array('integer' => array()),
				'maturity'    => array('integer' => array())
			)));
		}

//!	Get a list of events with optional filters
/**
*	@param integer $start Start point
*	@param integer $count Maximum number of results to fetch in initial call
*	@param array $filter columns to filter by
*	@param array $sort fields to sort by
*	@param boolean $asArray controls whether to return an iterator object or a raw result array.
*	@return object instance of Aurora::Addon::WebUI::GetEvents
*/
		public function GetEvents($start=0, $count=10, array $filter=null, array $sort=null, $asArray=false){
			if(is_string($start) === true && ctype_digit($start) === true){
				$start = (integer)$start;
			}
			if(is_string($count) === true && ctype_digit($count) === true){
				$count = (integer)$count;
			}

			if(is_integer($start) === false){
				throw new InvalidArgumentException('Start point must be specified as integer.');
			}else if(is_integer($count) === false){
				throw new InvalidArgumentException('Count must be specified as integer.');
			}else if($count < 0){
				throw new InvalidArgumentException('Count must be greater than or equal to zero.');
			}

			$input = array(
				'Start' => $start,
				'Count' => $count
			);
			if(isset($filter) === true){
				$input['Filter'] = $filter;
			}
			if(isset($sort) === true){
				$input['Sort'] = $sort;
			}

			$result = $this->makeCallToAPI('GetEvents', true, $input, array(
				'Events' => array('array'=>array( static::EventsResultValidatorArray())),
				'Total'  => array('integer'=>array())
			));
			$events = array();
			foreach($result->Events as $event){
				$events[] = WebUI\EventData::r(
					$event->eventID,
					$event->creator,
					$event->name,
					$event->description,
					$event->category,
					DateTime::createFromFormat('U', $event->dateUTC),
					$event->duration,
					$event->cover,
					$event->simName,
					new Vector3($event->globalPos[0], $event->globalPos[1], $event->globalPos[2]) ,
					$event->eventFlags,
					$event->maturity
				);
			}
			return $asArray ? $events : WebUI\GetEvents::r($this, $start, $result->Total, $filter, $sort, $events);
		}

//!	Adds an event to the grid directory
/**
*	@param object $creator User to list as the creator
*	@param object $region Region the event is hosted in
*	@param object $date Date & Time the event will be held
*	@param integer $cover length of event
*	@param integer $maturity indicates content rating of event
*	@param integer $eventFlags bitfield
*	@param integer $duration number of minutes the event lasts for
*	@param object $localPos location of event within region
*	@param string $name event subject
*	@param string $description event description
*	@param string $category event category
*	@return object Instance of Aurora::Addon::WebUI::EventData
*/
		public function CreateEvent(WebUI\abstractUser $creator, WebUI\GridRegion $region, DateTime $date, $cover, $maturity, $eventFlags, $duration, Vector3 $localPos, $name, $description, $category){
			if(is_string($cover) === true && ctype_digit($cover) === true){
				$cover = (integer)$cover;
			}
			if(is_string($maturity) === true && ctype_digit($maturity) === true){
				$maturity = (integer)$maturity;
			}
			if(is_string($eventFlags) === true && ctype_digit($eventFlags) === true){
				$eventFlags = (integer)$eventFlags;
			}
			if(is_string($duration) === true && ctype_digit($duration) === true){
				$duration = (integer)$duration;
			}
			if(is_string($name) === true){
				$name = trim($name);
			}
			if(is_string($description) === true){
				$description = trim($description);
			}
			if(is_string($category) === true){
				$category = trim($category);
			}

			if(is_integer($cover) === false){
				throw new InvalidArgumentException('Cover must be specified as integer.');
			}else if($cover < 0){
				throw new InvalidArgumentException('Cover must be greater than or equal to zero');
			}else if(is_integer($maturity) === false){
				throw new InvalidArgumentException('Maturity must be specified as integer.');
			}else if($maturity < 0){
				throw new InvalidArgumentException('Maturity must be greater than or equal to zero');
			}else if(is_integer($eventFlags) === false){
				throw new InvalidArgumentException('Flags must be specified as integer.');
			}else if($eventFlags < 0){
				throw new InvalidArgumentException('Flags must be greater than or equal to zero');
			}else if(is_integer($duration) === false){
				throw new InvalidArgumentException('Duration must be specified as integer.');
			}else if($duration <= 0){
				throw new InvalidArgumentException('Duration must be greater than zero');
			}else if(is_string($name) === false){
				throw new InvalidArgumentException('Name must be specified as string.');
			}else if($name === ''){
				throw new InvalidArgumentException('Name must be non-empty string.');
			}else if(is_string($description) === false){
				throw new InvalidArgumentException('Description must be specified as string.');
			}else if($description === ''){
				throw new InvalidArgumentException('Description must be non-empty string.');
			}else if(is_string($category) === false){
				throw new InvalidArgumentException('Category must be specified as string.');
			}else if($category === ''){
				throw new InvalidArgumentException('Category must be non-empty string.');
			}

			$event = $this->makeCallToAPI('CreateEvent', false, array(
				'Creator'     => $creator->PrincipalID(),
				'Region'      => $region->RegionID(),
				'Parcel'      => '00000000-0000-0000-0000-000000000000',
				'Date'        => $date->format('c'),
				'Cover'       => $cover,
				'Maturity'    => $maturity,
				'EventFlags'  => $eventFlags,
				'Duration'    => $duration,
				'Position'    => (string)$localPos,
				'Name'        => $name,
				'Description' => $description,
				'Category'    => $category
			), array(
				'Event' => static::EventsResultValidatorArray()
			))->Event;

			return WebUI\EventData::r(
				$event->eventID,
				$event->creator,
				$event->name,
				$event->description,
				$event->category,
				DateTime::createFromFormat('U', $event->dateUTC),
				$event->duration,
				$event->cover,
				$event->simName,
				new Vector3($event->globalPos[0], $event->globalPos[1], $event->globalPos[2]) ,
				$event->eventFlags,
				$event->maturity
			);
		}

#endregion
	}
}

namespace{
	require_once('WebUI/abstracts.php');

	require_once('WebUI/GridInfo.php');
	require_once('WebUI/Regions.php');
	require_once('WebUI/Parcels.php');
	require_once('WebUI/User.php');
	require_once('WebUI/Group.php');
	require_once('WebUI/Events.php');

	require_once('WebUI/AbuseReports.php');
	require_once('WebUI/AvatarArchives.php');
	require_once('WebUI/Friends.php');
}

//!	Code specific to the WebUI
namespace Aurora\Addon\WebUI{

	use Aurora\Addon\WORM;

//!	Long-term goal of libAurora.php is to support multiple grids on a single website, so we need an iterator to hold all the configs.
	class Configs extends WORM{

//!	singleton method.
/**
*	@return object an instance of Aurora::Addon::WebUI::Configs
*/
		public static function i(){
			static $instance;
			if(isset($instance) === false){
				$instance = new static();
			}
			return $instance;
		}

//!	Shorthand method for getting the default instance of Aurora::Addon::WebUI without having to call Aurora::Addon::WebUI::reset() all the time.
/**
*	@return object an instance of Aurora::Addon::WebUI
*/
		public static function d(){
			if(static::i()->offsetExists(0) === false){
				throw new BadMethodCallException('No configs have been set.');
			}
			return static::i()->offsetGet(0);
		}

//!	Restricts offsets to integers and values to instances of Aurora::Addon::WebUI
		public function offsetSet($offset, $value){
			if(($value instanceof \Aurora\Addon\WebUI) === false){
				throw new InvalidArgumentException('Only instances of Aurora::Addon::WebUI can be added to instances of Aurora::Addon::WebUI::Configs');
			}else if(isset($offset) === true && is_integer($offset) === false){
				throw new InvalidArgumentException('Only integer offsets allowed.');
			}

			$offset = isset($offset) ? $offset : $this->count();

			if(isset($this[$offset]) === true){
				throw new InvalidArgumentException('Configs cannot be overwritten.');
			}

			$this->data[$offset] = $value;
		}
	}

//!	WORM array of booleans
	class BoolWORM extends WORM{

//!	replacing the constructor
/**
*	@param array $bools array of booleans
*/
		protected function __construct(array $bools){
			foreach($bools as $k=>$v){
				if(is_bool($v) === false){
					throw new InvalidArgumentException('Values must be boolean');
				}else if(preg_match('/^[A-z][A-z0-9]*$/', $k) != 1){
					throw new InvalidArgumentException('Key was invalid');
				}
			}
			$this->data = $bools;
		}

//!	factory method
/**
*	@param array $bools array of booleans
*	@return object instance of Aurora::Addon::BoolWORM
*/
		public static function f(array $bools){
			return new static($bools);
		}

//!	Restricts values to booleans
		public function offsetSet($offset, $value){
			if(is_bool($value) === false){
				throw new InvalidArgumentException('Values must be boolean');
			}else if(preg_match('/^[A-z][A-z0-9]*$/', $offset) != 1){
				throw new InvalidArgumentException('Key was invalid');
			}else if($this->offsetExists($offset) === true){
				throw new BadMethodCallException('WORM instance values cannot be overwritten.');
			}
		}
	}
}
?>

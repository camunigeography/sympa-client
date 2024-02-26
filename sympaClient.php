<?php

# Client to connect to Sympa mailing lists

# See: https://www.sympa.community/manual/customize/soap-api.html

/*
# Example usage:
$sympaClient = new sympaClient ($email, $password, $soapServer);
$html = $sympaClient->complexLists ();
echo $html;
*/


# Class to create a client for connection to Sympa
class sympaClient
{
	# Properties
	private $connection;
	private $email;
	private $md5Token;
	private $errors = array ();
	private $sleepSeconds = 5;
	
	
	# Constructor
	public function __construct ($soapServer, $email, $password, $baseUrl = './', $sympaDomainInScope = false /* e.g. '@example.com' */, $echoErrors = true, $addNamesSuffix = '[Auto-maintained]')
	{
		# Register properties
		$this->email = $email;
		$this->baseUrl = $baseUrl;
		$this->sympaDomainInScope = $sympaDomainInScope;
		$this->echoErrors = $echoErrors;
		$this->addNamesSuffix = $addNamesSuffix;
		
		# Create the client
		try {
			$this->connection = new SoapClient ($soapServer);
			$this->connection->debug_flag = true;
			$this->md5Token = $this->connection->login ($email, $password);
		} catch (SoapFault $e) {
			$errorString = $e->faultstring . (isSet ($e->detail) ? ': ' . $e->detail : '');
			$this->errors[] = $errorString;
			if ($this->echoErrors) {
				echo "\n" . 'ERROR (initial connection): ' . $errorString;
			}
			return false;
		}
		
	}
	
	
	# Getter for errors
	public function getErrors ()
	{
		return $this->errors;
	}
	
	
	# Setter for sleep seconds
	public function setSleepSeconds ($sleepSeconds)
	{
		$this->sleepSeconds = $sleepSeconds;
	}
	
	
	
	# Function to get data
	private function getData ($function, $parameters = array (), $isSingular = false, &$errorString = false)
	{
		# Get data; see: https://www.sympa.community/manual/customize/soap-api.html
		try {
			$data = $this->connection->authenticateAndRun ($this->email, $this->md5Token, $function, $parameters);
		} catch (SoapFault $e) {
			//var_dump ($e);
			$errorString = $e->faultstring . ': ' . (isSet ($e->detail) ? ': ' . $e->detail : '');
			$this->errors[] = $errorString;
			if ($this->echoErrors) {
				echo "\n" . 'ERROR (getData): ' . $errorString;
			}
			return false;
		}
		//var_dump ($data);
		
		# End if no data
		if (!$data) {
			return false;
		}
		
		# For a singular response, extract
		if ($isSingular) {
			$data = $data[0];
		}
		
		# Return the result object
		return $data;
	}
	
	
	# Function to get lists and display them
	public function complexLists ($parameters = array ())
	{
		# Get the data
		$lists = $this->getData (__FUNCTION__, $parameters);
		
		# End if none
		if (!$lists) {
			$html = "\n<p>No lists.</p>";
			return $html;
		}
		
		# Show each list
		$listsHtml = array ();
		foreach ($lists as $list) {
			$listsHtml[] = $this->renderList ($list);
		}
		
		# Compile the HTML
		require_once ('application.php');
		$html = application::htmlUl ($listsHtml);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to render a list's details
	private function renderList ($list)
	{
		# Assemble entry
		list ($list->listName, $list->listDomain) = explode ('@', $list->listAddress);
		$html  = $list->listAddress;
		$html .= ' [<a href="' . $this->baseUrl . '/subscribe/' . $list->listName . '">subscribe</a>]';
		$html .= ' [<a href="' . $list->homepage . '">info</a>]<br />';
		$html .= $list->subject;
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to get list info (summary)
	# https://www.sympa.community/manual/customize/soap-api.html#info
	public function info ($listname)
	{
		# Get the data
		$parameters = array ($listname);
		$list = $this->getData (__FUNCTION__, $parameters, true);
		
		# Compile the HTML
		$html = $this->renderList ($list);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to get list members
	# https://www.sympa.community/manual/customize/soap-api.html#review
	public function review ($listname, $names = array ())
	{
		# Get the data
		$parameters = array ($listname);
		if (!$members = $this->getData (__FUNCTION__, $parameters)) {
			$html = "\n<p>Could not get the list of subscribers.</p>";
			return $html;
		}
		
		# End if none
		if ($members[0] == 'no_subscribers') {
			$html = "\n<p>No subscribers.</p>";
			return $html;
		}
		
		# Render the list as a table if there are names provided; the Sympa API itself does not return the name strings, but we already have most of them anyway
		if ($names) {
			$table = array ();
			foreach ($members as $index => $email) {
				$forename = false;
				$surname = false;
				if (isSet ($names[$email])) {
					$nameComponents = preg_split ('/\s+/', trim ($names[$email]));
					$surname = array_pop ($nameComponents);
					$forename = implode (' ', $nameComponents);
				}
				$table[] = array (
					'username' => (str_ends_with ($email, $this->sympaDomainInScope) ? str_replace ($this->sympaDomainInScope, '', $email) : ''),
					'forename' => $forename,
					'surname' => $surname,
					'email' => $email,
				);
			}
			$tableHeadingSubstitutions = array ('username' => 'Username', 'forename' => 'Forename', 'surname' => 'Surname', 'email' => 'E-mail', );
			$html = application::htmlTable ($table, $tableHeadingSubstitutions, 'lines compressed peopleemailslist sortable" id="sortable', $keyAsFirstColumn = false, false, false, false, $addCellClasses = true);
			
		# If no names, just show simple bullet-point list
		} else {
			$html = application::htmlUl ($members, 0, NULL, true, $sanitise = true);
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to add a member
	# https://www.sympa.community/manual/customize/soap-api.html#add
	public function add ($listname, $email, $gecos /* name */ = false, $quiet = false)
	{
		# Start the HTML
		$html = '';
		
		# Get the data
		$parameters = array ($listname, $email, $gecos . ($gecos ? ' ' . $this->addNamesSuffix : ''), $quiet);
		$result = $this->getData (__FUNCTION__, $parameters);
		
		# End if none
		#!# Actually fails with : "Fatal error: Uncaught SoapFault exception: [soap:Server] Undef in sympaClient.php on line 48", "SoapFault: Undef in sympaClient.php on line 48"
		if (!$result) {
			$errorString = "Failed adding user {$email} to list {$listname}.";
			$html = "\n<p>" . htmlspecialchars ($errorString) . '</p>';
			if ($this->echoErrors) {
				echo "\n" . "ERROR ({$listname} add): " . $errorString;
			}
			return $html;
		}
		
		# Success
		$html = "\n<p>User {$email} added.</p>";
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to remove a member
	# https://www.sympa.community/manual/customize/soap-api.html#del
	public function del ($listname, $email, $quiet = false)
	{
		# Ensure the e-mail to be deleted is in scope, if required
		if ($this->sympaDomainInScope) {
			if (!str_ends_with ($email, $this->sympaDomainInScope)) {
				$html = "\n<p>Notice: {$email} is out of scope from automated management, and so is not deleted.</p>";
				return $html;
			}
		}
		
		# Get the data
		$parameters = array ($listname, $email, $quiet);
		$result = $this->getData (__FUNCTION__, $parameters);
		
		# End if none
		#!# Actually fails with : "Fatal error: Uncaught SoapFault exception: [soap:Server] Undef in sympaClient.php on line 48", "SoapFault: Undef in sympaClient.php on line 48"
		if (!$result) {
			$errorString = "Failed deleting user {$email}.";
			$html = "\n<p>" . htmlspecialchars ($errorString) . '</p>';
			if ($this->echoErrors) {
				echo "\n" . "ERROR ({$listname} del): " . $errorString;
			}
			return $html;
		}
		
		# Success
		$html = "\n<p>User {$email} deleted.</p>";
		
		# Return the HTML
		return $html;
	}
	
	
	# Wrapper to add multiple members
	public function _addMany ($listname, $users /* array (email1 => name1, email2 => name2, ...) */, $quiet = '1')
	{
		# Start the HTML
		$html = '';
		
		# Delete each e-mail
		foreach ($users as $email => $name) {
			$html .= $this->add ($listname, $email, $name, $quiet);
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Wrapper to remove multiple members
	public function _delMany ($listname, $emails, $quiet = '1')
	{
		# Start the HTML
		$html = '';
		
		# Delete each e-mail
		foreach ($emails as $email) {
			$html .= $this->del ($listname, $email, $quiet);
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Wrapper to update list members
	public function _updateMembers ($listname, $users)
	{
		# Start the HTML
		$html = '';
		
		# Get the list of users
		#!# Use ->review() internally
		$parameters = array ($listname);
		$currentEmails = $this->getData ('review', $parameters, false, $errorString /* returned by reference */);
		sleep ($this->sleepSeconds);
		if ($errorString) {
			$errorString .= '.';
			$html = "\n" . '<p class="warning">' . htmlspecialchars ($errorString) . '</p>';
			if ($this->echoErrors) {
				echo "\n" . "ERROR ({$listname} _updateMembers): " . $errorString;
			}
			return $html;
		}
		
		# Add users not currently in this list
		$desiredEmails = array_keys ($users);
		$addEmails = array_diff ($desiredEmails, $currentEmails);
		foreach ($addEmails as $email) {
			$html .= $this->add ($listname, $email, $users[$email], '1');
		}
		
		# Remove users not currently in this list
		$removeEmails = array_diff ($currentEmails, $desiredEmails);
		foreach ($removeEmails as $email) {
			$html .= $this->del ($listname, $email, '1');
		}
		
		# Confirm list
		sleep ($this->sleepSeconds);
		$html .= "\n<p>The list of members is now:</p>";
		$html .= $this->review ($listname, $users);
		
		# Return the HTML
		return $html;
	}
}

?>

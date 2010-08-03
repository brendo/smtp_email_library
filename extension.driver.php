<?php

	require_once(dirname(__FILE__) . '/lib/class.email.php');

	Class Extension_SMTP_Email_Library implements iExtension {

		const HTACCESS_PEAR_INCLUDE = "## EMAIL EXTENSION PEAR LIBRARY\nphp_value include_path  \"extensions/smtp_email_library/lib/pear:.\"";

		public function about() {
			return (object)array(
				'name' => 'SMTP Email Library',
				'version' => '2.0',
				'release-date' => '2010-08-03',
				'author' => (object)array(
					'name' => 'Alistair Kearney',
					'website' => 'http://www.pointybeard.com',
					'email' => 'alistair@symphony-cms.com'
				),
				'type'			=> array(
					'Email'
				)
			);
		}

	/*-------------------------------------------------------------------------
		Definition:
	-------------------------------------------------------------------------*/
		public function install(){
			$this->enable();
		}

		public function uninstall(){
			$this->disable();
			General::deleteFile(CONF . '/smtp-email-library.xml');
		}

		public function enable(){
			return self::updateHtaccess();
		}

		public function disable(){
			return self::updateHtaccess(true);
		}

		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/system/settings/extensions/',
					'delegate' => 'AddSettingsFieldsets',
					'callback' => 'cbAppendPreferences'
				),

				array(
					'page' => '/system/settings/extensions/',
					'delegate' => 'CustomSaveActions',
					'callback' => 'cbSavePreferences'
				),

				array(
					'page' => '/blueprints/events/',
					'delegate' => 'AppendEventFilter',
					'callback' => 'cbAddFilterToEventEditor'
				),

				array(
					'page' => '/frontend/',
					'delegate' => 'EventPostSaveFilter',
					'callback' => 'cbSendEmailSMTPFilter'
				),
			);
		}

	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/

		private static function updateHtaccess($removing=false){

			$htaccess = file_get_contents(DOCROOT . '/.htaccess');
			if($htaccess === false) return false;

			## Remove existing rules
			$htaccess = str_replace(self::HTACCESS_PEAR_INCLUDE, NULL, $htaccess);

			if($removing == false){
				$htaccess = preg_replace(
					'/### Symphony 3\.0\.x ###\n*/i',
					"### Symphony 3.0.x ###\n\n" . self::HTACCESS_PEAR_INCLUDE . "\n\n",
					$htaccess
				);
			}
			else{
				//clean up the extra new line characters
				$htaccess = preg_replace(
					'/### Symphony 3\.0\.x ###\n*/i',
					"### Symphony 3.0.x ###\n",
					$htaccess
				);
			}

			return file_put_contents(DOCROOT . '/.htaccess', $htaccess);
		}

		public static function findFormValueByNeedle($needle, $haystack, $default=NULL, $discard_field_name=true, $collapse=true){

			if(preg_match('/^(fields\[[^\]]+\],?)+$/i', $needle)){
				$parts = preg_split('/\,/i', $needle, -1, PREG_SPLIT_NO_EMPTY);
				$parts = array_map('trim', $parts);

				$stack = array();
				foreach($parts as $p){
					$field = str_replace(array('fields[', ']'), '', $p);
					($discard_field_name ? $stack[] = $haystack[$field] : $stack[$field] = $haystack[$field]);
				}

				if(is_array($stack) && !empty($stack)) return ($collapse ? implode(' ', $stack) : $stack);
				else $needle = NULL;
			}

			$needle = trim($needle);
			if(empty($needle)) return $default;

			return $needle;
		}

	/*-------------------------------------------------------------------------
		Preferences:
	-------------------------------------------------------------------------*/

		public function cbAppendPreferences($context){

			$document = Administration::instance()->Page;

			$fieldset = $document->createElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild($document->createElement('h3', __('SMTP Email Library')));

			$group = $document->createElement('div');
			$group->setAttribute('class', 'group');

			$label = Widget::Label(__('Host'));
			$label->appendChild(Widget::Input('smtp-email-library[host]', Symphony::Configuration()->{'smtp-email-library'}()->host));
			$group->appendChild($label);

			$label = Widget::Label(__('Port'));
			$label->appendChild(Widget::Input('smtp-email-library[port]', Symphony::Configuration()->{'smtp-email-library'}()->port));
			$group->appendChild($label);
			$fieldset->appendChild($group);

			$label = Widget::Label();
			$input = Widget::Input('smtp-email-library[auth]', 'yes', 'checkbox');
			if(Symphony::Configuration()->{'smtp-email-library'}()->auth == 'yes') $input->setAttribute('checked', 'checked');
			$label->appendChild($input);
			$label->appendChild(new DOMText(' ' . __('Authentication Required')));
			$fieldset->appendChild($label);

			$fieldset->appendChild($document->createElement(
				'p',
				__('Some SMTP connections require authentication. If that is the case, enter the username/password combination below.'),
				array('class' => 'help')
			));

			$group = $document->createElement('div');
			$group->setAttribute('class', 'group');

			$label = Widget::Label(__('Username'));
			$label->appendChild(Widget::Input('smtp-email-library[username]', Symphony::Configuration()->{'smtp-email-library'}()->username));
			$group->appendChild($label);

			$label = Widget::Label(__('Password'));
			$label->appendChild(Widget::Input('smtp-email-library[password]', Symphony::Configuration()->{'smtp-email-library'}()->password));
			$group->appendChild($label);
			$fieldset->appendChild($group);

			$context['fieldsets'][] = $fieldset;
		}

		public function cbSavePreferences($context) {
			Symphony::Configuration()->{'smtp-email-library'}()->auth = (isset($_POST['smtp-email-library']['auth']))
				? $_POST['smtp-email-library']['auth']
				: 'no';

			Symphony::Configuration()->{'smtp-email-library'}()->host = $_POST['smtp-email-library']['host'];
			Symphony::Configuration()->{'smtp-email-library'}()->port = $_POST['smtp-email-library']['port'];
			Symphony::Configuration()->{'smtp-email-library'}()->username = $_POST['smtp-email-library']['username'];
			Symphony::Configuration()->{'smtp-email-library'}()->password = $_POST['smtp-email-library']['password'];

			Symphony::Configuration()->{'smtp-email-library'}()->save();
		}

	/*-------------------------------------------------------------------------
		Event Filter:
	-------------------------------------------------------------------------*/

		public function cbAddFilterToEventEditor($context){
			$selected = is_array($context['selected']) ? in_array('smtp-email-library-send-email-filter', $context['selected']) : false;

			$context['options'][] = array(
				'smtp-email-library-send-email-filter', $selected, 'Send Email via Direct SMTP Connection'
			);
		}

		public function cbSendEmailSMTPFilter(array $context=array()){

			if(!@in_array('smtp-email-library-send-email-filter', $context['event']->eParamFILTERS)) return;

			$fields = $_POST['send-email'];

			$fields['recipient'] = Extension_SMTP_Email_Library::findFormValueByNeedle($fields['recipient'], $_POST['fields']);
			$fields['recipient'] = preg_split('/\,/i', $fields['recipient'], -1, PREG_SPLIT_NO_EMPTY);
			$fields['recipient'] = array_map('trim', $fields['recipient']);

			if(is_array($fields['recipient'] && !empty($fields['recipient']))) {
				$fields['recipient'] = Symphony::Database()->fetch("SELECT `email`, CONCAT(`first_name`, ' ', `last_name`) AS `name` FROM `tbl_authors` WHERE `username` IN ('".@implode("', '", $fields['recipient'])."') ");
			}
			else {
				$context['messages'][] = array('smtp-email-library-send-email-filter', false, __('No valid recipients found. Check send-email[recipient] field.'));
			}

			$fields['subject'] = Extension_SMTP_Email_Library::findFormValueByNeedle($fields['subject'], $context['fields'], __('[Symphony] A new entry was created on %s', array(Symphony::Configuration()->get('sitename', 'general'))));
			$fields['body'] = Extension_SMTP_Email_Library::findFormValueByNeedle($fields['body'], $context['fields'], null, false, false);
			$fields['sender-email'] = Extension_SMTP_Email_Library::findFormValueByNeedle($fields['sender-email'], $context['fields'], 'noreply@' . parse_url(URL, PHP_URL_HOST));
			$fields['sender-name'] = Extension_SMTP_Email_Library::findFormValueByNeedle($fields['sender-name'], $context['fields'], 'Symphony');
			$fields['from'] = Extension_SMTP_Email_Library::findFormValueByNeedle($fields['from'], $context['fields'], $fields['sender-email']);

			$section = Symphony::Database()->fetchRow(0, "SELECT * FROM `tbl_sections` WHERE `id` = ".$context['event']->getSource()." LIMIT 1");

			$edit_link = URL.'/symphony/publish/'.$section['handle'].'/edit/'.$context['entry_id'].'/';

			$body = __('Dear <!-- RECIPIENT NAME -->,') . General::CRLF . General::CRLF . __('This is a courtesy email to notify you that an entry was created on the %1$s section. You can edit the entry by going to: %2$s', array($section['name'], $edit_link)). General::CRLF . General::CRLF;

			if(is_array($fields['body'])){
				foreach($fields['body'] as $field_handle => $value){
					$body .= "=== $field_handle ===" . General::CRLF . General::CRLF . $value . General::CRLF . General::CRLF;
				}
			}

			else $body .= $fields['body'];

			$errors = array();

			if(!is_array($fields['recipient']) || empty($fields['recipient'])){
				$context['messages'][] = array('smtp-email-library-send-email-filter', false, __('No valid recipients found. Check send-email[recipient] field.'));
			}

			else{

				foreach($fields['recipient'] as $r){

					$email = new LibraryEmail;

					$email->to = vsprintf('%2$s <%1$s>', array_values($r));
					$email->from = sprintf('%s <%s>', $fields['sender-name'], $fields['sender-email']);
					$email->subject = $fields['subject'];
					$email->message = str_replace('<!-- RECIPIENT NAME -->', $r['name'], $body);
					$email->setHeader('Reply-To', $fields['from']);

					try{
						$email->send();
					}
					catch(Exception $e){
						$errors[] = $email;
					}

				}

				if(!empty($errors)){
					$context['messages'][] = array('smtp-email-library-send-email-filter', false, 'The following email addresses were problematic: ' . General::sanitize(implode(', ', $errors)));
				}

				else $context['messages'][] = array('smtp-email-library-send-email-filter', true);
			}
		}

	/*-------------------------------------------------------------------------
		Deprecated [only because there is no way to show this (important) information in S3 yet]
	-------------------------------------------------------------------------*/

		public function cbAppendEventFilterDocumentation(array $context=array()){
			if(!@in_array('smtp-email-library-send-email-filter', $context['selected'])) return;

			$context['documentation'][] = new XMLElement('h3', __('Send Email via Direct SMTP Connection'));

			$context['documentation'][] = new XMLElement('p', __('The send email filter, upon the event successfully saving the entry, takes input from the form and send an email to the desired recipient. <b>This filter currently does not work with the "Allow Multiple" option.</b> The following are the recognised fields:'));

			$context['documentation'][] = contentBlueprintsEvents::processDocumentationCode(
				'send-email[sender-email] // '.__('Optional').self::CRLF.
				'send-email[sender-name] // '.__('Optional').self::CRLF.
				'send-email[subject] // '.__('Optional').self::CRLF.
				'send-email[body]'.self::CRLF.
				'send-email[recipient] // '.__('comma separated list of author usernames.'));

			$context['documentation'][] = new XMLElement('p', __('All of these fields can be set dynamically using the exact field name of another field in the form as shown below in the example form:'));

	        $context['documentation'][] = contentBlueprintsEvents::processDocumentationCode('
				<form action="" method="post">
					<fieldset>
						<label>'.__('Name').' <input type="text" name="fields[author]" value="" /></label>
						<label>'.__('Email').' <input type="text" name="fields[email]" value="" /></label>
						<label>'.__('Message').' <textarea name="fields[message]" rows="5" cols="21"></textarea></label>
						<input name="send-email[sender-email]" value="fields[email]" type="hidden" />
						<input name="send-email[sender-name]" value="fields[author]" type="hidden" />
						<input name="send-email[subject]" value="You are being contacted" type="hidden" />
						<input name="send-email[body]" value="fields[message]" type="hidden" />
						<input name="send-email[recipient]" value="fred" type="hidden" />
						<input id="submit" type="submit" name="action[save-contact-form]" value="Send" />
					</fieldset>
				</form>
			');
		}

	}

	return 'Extension_SMTP_Email_Library';
<?php
namespace App\Account;
use Core, UI, Util, API, App\Tokenly;
class Settings_Model extends Core\Model
{
	protected function getSettingsForm($user, $adminView = false)
	{
		$app = get_app('account');
		$getSite = currentSite();
		$form = new UI\Form;
		$form->setFileEnc();
		
		if($adminView){
			$username = new UI\Textbox('username');
			$username->setLabel('Username');
			$username->addAttribute('required');
			$form->add($username);
		}
		
		$email = new UI\Textbox('email');
		$email->setLabel('Email Address');
		$form->add($email);
		
		
		if(!$adminView){
			$pass = new UI\Password('password');
			$pass->setLabel('New Password');
			$pass->addAttribute('autocomplete', 'off');
			$form->add($pass);
			
			$pass2 = new UI\Password('password2');
			$pass2->setLabel('New Password (repeat)');
			$pass2->addAttribute('autocomplete', 'off');
			$form->add($pass2);
		}
		
		$showEmail = new UI\Checkbox('showEmail');
		$showEmail->setLabel('Show email address in profile?');
		$showEmail->setBool(1);
		$showEmail->setValue(1);
		$form->add($showEmail);
		
		$pubProf = new UI\Checkbox('pubProf');
		$pubProf->setLabel('Make profile public?');
		$pubProf->setBool(1);
		$pubProf->setValue(1);
		$form->add($pubProf);
		
		$emailNotify = new UI\Checkbox('emailNotify');
		$emailNotify->setLabel('Email me when notification is received?');
		$emailNotify->setBool(1);
		$emailNotify->setValue(1);
		$form->add($emailNotify);
		
		
		$btcAccess = new UI\Checkbox('btc_access');
		$btcAccess->setLabel('Enable API account access via verified bitcoin address?');
		$btcAccess->setBool(1);
		$btcAccess->setValue(1);
		$form->add($btcAccess);
		
		if(!$adminView){
			$pass = new UI\Password('curPassword');
			$pass->setLabel('Enter current password to complete changes');
			$pass->addAttribute('required');
			$form->add($pass);
		}
		
		if($adminView){
			$activate = new UI\Checkbox('activated');
			$activate->setBool(1);
			$activate->setValue(1);
			$activate->setLabel('Account Active?');
			$form->add($activate);
		}

		return $form;
	}
	
	protected function updateSettings($user, $data, $isAPI = false, $adminView = false)
	{
		$app = get_app('account');
		$auth_model = new Auth_Model;
		$data['admin_mode'] = $adminView;
		$data['is_api'] = $isAPI;
		$auth_model->updateAccount($user['userId'], $data);
	
		$meta = new \App\Meta_Model;
		if(isset($data['pubProf']) AND intval($data['pubProf']) === 1){
			$meta->updateUserMeta($user['userId'], 'pubProf', 1);
		}
		else{
			$meta->updateUserMeta($user['userId'], 'pubProf', 0);
		}
		if(isset($data['showEmail'])){
			$meta->updateUserMeta($user['userId'], 'showEmail', $data['showEmail']);
		}
		if(isset($data['emailNotify'])){
			$meta->updateUserMeta($user['userId'], 'emailNotify', $data['emailNotify']);
		}
		
		if(isset($data['btc_access'])){
			$meta->updateUserMeta($user['userId'], 'btc_access', $data['btc_access']);
		}

		$avWidth = $app['meta']['avatarWidth'];
		$avHeight = $app['meta']['avatarHeight'];
		
		
		//keep this in for API compatibility for now
		//turn this into a mod
		if(!$isAPI){
			if(isset($_FILES['avatar']['tmp_name']) AND trim($_FILES['avatar']['tmp_name']) != ''){
				$picName = md5($user['username'].$_FILES['avatar']['name']).'.jpg';
				$upload = Util\Image::resizeImage($_FILES['avatar']['tmp_name'], SITE_PATH.'/files/avatars/'.$picName, $avWidth, $avHeight);
				if($upload){
					$meta->updateUserMeta($user['userId'], 'avatar', $picName);
				}
			}
		}
		else{
			if(isset($data['avatar'])){
				$tmpName = 'av-'.hash('sha256', mt_rand(0,10000).':'.$user['username'].time());
				$saveTmp = file_put_contents('/tmp/'.$tmpName, $data['avatar']);
				if($saveTmp){
					$getMime = @getimagesize('/tmp/'.$tmpName);
					if($getMime){
						$picName = md5($user['username'].$tmpName).'.jpg';
						$upload = Util\Image::resizeImage('/tmp/'.$tmpName, SITE_PATH.'/files/avatars/'.$picName, $avWidth, $avHeight);
						if($upload){
							$meta->updateUserMeta($user['userId'], 'avatar', $picName);
						}
					}
					unlink('/tmp/'.$tmpName);
				}
				
			}
		}

		return true;
	}
	
	protected function getSettingsInfo($user)
	{
		$meta = new \App\Meta_Model;
		$output = array('email' => $user['email']);
		$output['pubProf'] = $meta->getUserMeta($user['userId'], 'pubProf');
		$output['showEmail'] = $meta->getUserMeta($user['userId'], 'showEmail');
		$output['emailNotify'] = $meta->getUserMeta($user['userId'], 'emailNotify');
		$output['btc_access'] = $meta->getUserMeta($user['userId'], 'btc_access');
		$output['username'] = $user['username'];
		if(!isset($user['activated'])){
			$user['activated'] = 1;
		}
		$output['activated'] = $user['activated'];
		
		return $output;
	}
	
	protected function getDeleteForm()
	{
		$form = new UI\Form;

		$pass = new UI\Password('password');
		$pass->setLabel('Enter your password to close and delete your account');
		$pass->addAttribute('required');
		$form->add($pass);

		return $form;	
	}
	
	protected function deleteAccount($user, $data)
	{
		$getUser = $this->get('users', $user['userId']);
		if(!isset($data['password'])){
			throw new \Exception('Current password required to complete deletion');
		}
		
		$checkPass = hash('sha256', $getUser['spice'].$data['password']);
		if($checkPass != $getUser['password']){
			throw new \Exception('Incorrect password!');
		}
		
		$delete = $this->delete('users', $getUser['userId']);
		if(!$delete){
			throw new \Exception('Error deleting account, please try again');
		}
		
		Util\Session::clear('accountAuth');
		
		return true;
		
	}
	
	protected function checkEmailInUse($userId, $email)
	{
		$get = $this->getAll('users', array('email' => $email), array('userId'));
		if(!$get){
			return false;
		}
		foreach($get as $row){
			if($row['userId'] != $userId){
				return true;
			}
		}

		return false;
	}
}

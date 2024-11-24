<?php


class UserService {
    public function isUserAnAdminOrSuperAdmin($userType) {
        return $userType == constant('ADMIN_ROLE_ID') || $userType == constant('SUPERADMIN_ROLE_ID');
    }
}
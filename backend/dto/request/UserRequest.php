<?php

class UserRequestDTO {
    public string $username;
    public string $email;
    public string $password; // plain password, will be hashed
    public ?string $displayName;
    public ?string $bio;
    public ?string $avatarUrl;
    public ?string $favoriteUnit;

    public function __construct(
        string $username,
        string $email,
        string $password,
        ?string $displayName = null,
        ?string $bio = null,
        ?string $avatarUrl = null,
        ?string $favoriteUnit = null
    ) {
        $this->username     = $username;
        $this->email        = $email;
        $this->password     = $password;
        $this->displayName  = $displayName;
        $this->bio          = $bio;
        $this->avatarUrl    = $avatarUrl;
        $this->favoriteUnit = $favoriteUnit;
    }
}

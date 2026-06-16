<?php

class UpdateProfileRequest {
    public ?string $displayName;
    public ?string $bio;
    public ?string $favoriteUnit;
    public ?string $avatarUrl;

    public function __construct(
        ?string $displayName  = null,
        ?string $bio          = null,
        ?string $favoriteUnit = null,
        ?string $avatarUrl    = null
    ) {
        $this->displayName  = $displayName;
        $this->bio          = $bio;
        $this->favoriteUnit = $favoriteUnit;
        $this->avatarUrl    = $avatarUrl;
    }
}
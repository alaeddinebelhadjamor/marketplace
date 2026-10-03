<?php

namespace App\Models;

use App\Enums\SellerStatus;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Vendeur de la marketplace (table existante marketplace_seller).
 *
 * Le mot de passe est stocké dans la colonne password_hash (bcrypt), partagée
 * avec la v1 : les deux versions acceptent les mêmes comptes.
 */
class Seller extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasApiTokens, HasFactory, Notifiable;

    protected $table = 'marketplace_seller';

    protected $primaryKey = 'seller_id';

    /**
     * Champs modifiables par affectation de masse. Le statut et le hash ne
     * figurent pas ici : ils ne sont jamais pris tels quels depuis une requête.
     */
    protected $fillable = [
        'firstname', 'lastname', 'email', 'shop_title', 'company', 'contact_number',
        'description', 'address', 'zipcode', 'governorate', 'has_patent', 'tax_id', 'logo',
    ];

    protected $hidden = ['password_hash', 'remember_token'];

    // La table n'a pas de colonne remember_token.
    protected $rememberTokenName = '';

    protected function casts(): array
    {
        return [
            'seller_id' => 'integer',
            'has_patent' => 'integer',
            'status' => 'integer',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function statusEnum(): SellerStatus
    {
        return SellerStatus::from((int) $this->status);
    }

    public function isValidated(): bool
    {
        return (int) $this->status === SellerStatus::Validated->value;
    }

    public function reclamations(): HasMany
    {
        return $this->hasMany(Reclamation::class, 'vendeur_id', 'seller_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'vendor_id', 'seller_id');
    }

    public function twoFactor(): HasOne
    {
        return $this->hasOne(SellerTwoFactor::class, 'seller_id', 'seller_id');
    }

    public function hasTwoFactorEnabled(): bool
    {
        if ($this->relationLoaded('twoFactor')) {
            return $this->twoFactor?->confirmed_at !== null;
        }

        return $this->twoFactor()->whereNotNull('confirmed_at')->exists();
    }

    /** Canal privé de diffusion temps réel du vendeur. */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'seller.'.$this->seller_id;
    }
}

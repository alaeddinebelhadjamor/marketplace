<?php

namespace App\Services\Auth;

use App\Enums\SellerStatus;
use App\Exceptions\ApiException;
use App\Models\Seller;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;

/**
 * Règles métier des comptes vendeurs : inscription, vérification des
 * identifiants, changement de mot de passe.
 */
class SellerAuthService
{
    /**
     * Crée un vendeur « en attente de validation ».
     *
     * @throws ApiException 409 si l'email ou le nom de boutique est déjà utilisé
     */
    public function register(array $attributes, string $password): Seller
    {
        $email = trim((string) $attributes['email']);
        $shop = trim((string) $attributes['shop_title']);

        // Le nom de boutique sert aussi d'identifiant de connexion : il doit être
        // unique, bien que la table ne porte pas d'index unique sur cette colonne.
        $existing = Seller::query()
            ->where('email', $email)
            ->orWhere('shop_title', $shop)
            ->first(['seller_id', 'email', 'shop_title']);

        if ($existing) {
            throw new ApiException(
                (mb_strtolower($existing->email) === mb_strtolower($email) ? 'Email' : 'Nom de boutique').' déjà utilisé.',
                409
            );
        }

        $seller = new Seller(array_merge($attributes, ['email' => $email, 'shop_title' => $shop]));
        $seller->password_hash = Hash::make($password);
        $seller->status = SellerStatus::Pending->value;

        try {
            $seller->save();
        } catch (QueryException $e) {
            // Inscription simultanée avec le même email : l'index unique tranche.
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains($e->getMessage(), '1062')) {
                throw new ApiException('Email déjà utilisé.', 409);
            }
            throw $e;
        }

        return $seller;
    }

    public function findByIdentifier(string $identifier): ?Seller
    {
        $identifier = trim($identifier);

        return Seller::query()
            ->where('email', $identifier)
            ->orWhere('shop_title', $identifier)
            ->orderBy('seller_id')
            ->first();
    }

    /**
     * Vérifie un mot de passe. password_verify accepte les hash « $2b$ » écrits
     * par bcryptjs (v1) comme les hash « $2y$ » écrits par Laravel (v2).
     */
    public function checkPassword(Seller $seller, string $password): bool
    {
        $hash = (string) $seller->password_hash;

        return $hash !== '' && password_verify($password, $hash);
    }

    /**
     * Vérifie identifiant et mot de passe, puis le statut du compte.
     *
     * Amélioration v2 (annoncée au tableau 2.3 du rapport) : identifiant inconnu
     * et mot de passe faux renvoient le même 401, pour ne pas révéler
     * l'existence d'un compte.
     *
     * @throws ApiException 401 ou 403
     */
    public function attempt(string $identifier, string $password): Seller
    {
        $seller = $this->findByIdentifier($identifier);

        if (! $seller || ! $this->checkPassword($seller, $password)) {
            throw new ApiException('Identifiant ou mot de passe incorrect.', 401);
        }

        return match ((int) $seller->status) {
            SellerStatus::Pending->value => throw new ApiException('Compte en attente de validation.', 403),
            SellerStatus::Refused->value => throw new ApiException('Compte refusé.', 403),
            default => $seller,
        };
    }

    /** @throws ApiException 401 si le mot de passe actuel est faux */
    public function changePassword(Seller $seller, string $current, string $new): void
    {
        if (! $this->checkPassword($seller, $current)) {
            throw new ApiException('Mot de passe actuel incorrect.', 401);
        }

        $this->setPassword($seller, $new);
    }

    public function setPassword(Seller $seller, string $new): void
    {
        $seller->password_hash = Hash::make($new);
        $seller->save();
    }
}

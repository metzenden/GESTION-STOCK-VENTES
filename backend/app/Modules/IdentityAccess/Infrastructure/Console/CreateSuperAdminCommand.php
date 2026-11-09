<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Console;

use App\Modules\IdentityAccess\Domain\Enums\UserStatus;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Création d'un compte super-administrateur par l'exploitant (aucune création possible depuis l'API, ADM-004). */
final class CreateSuperAdminCommand extends Command
{
    protected $signature = 'app:create-super-admin {email} {name}';

    protected $description = 'Crée un compte super-administrateur (mot de passe saisi de façon masquée)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));
        $password = (string) $this->secret('Mot de passe (12 caractères minimum)');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
                'name' => ['required', 'string', 'min:2', 'max:160'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->forceFill([
            'company_id' => null,
            'is_super_admin' => true,
            'name' => $name,
            'email' => $email,
            'email_verified_at' => Carbon::now(),
            'password' => $password,
            'status' => UserStatus::Active,
        ])->save();

        $this->info("Super-administrateur créé : {$user->email}");

        return self::SUCCESS;
    }
}

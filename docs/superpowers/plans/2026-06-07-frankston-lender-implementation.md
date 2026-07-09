# Frankston Lender V1.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bygge risk-database-as-a-service-produkt til danske private debt lenders. V1.0 MVP til Draupnir pilot Q3 2026, free trial, 55-77 dages effort.

**Architecture:** Selvstændigt Laravel 12 + Livewire 3 + Flux Pro app på `lender.frankston.io` med egen Forge-deploy. PostgreSQL 16 (RLS + pgcrypto + PostGIS + materialized views). 3-lags multi-tenant isolation (middleware + Eloquent scope + DB RLS). READ-ONLY mod registry-api via tenant-scoped Sanctum-tokens.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL 16, Livewire 3, Flux Pro, Pest 4, Horizon, Pulse, Flare, Forge, Oh Dear.

**Spec reference:** `docs/superpowers/specs/2026-06-07-frankston-lender-product-design.md` (v3.1, confidence 86/100)

---

## File Structure (foundation)

```
frankston-lender/                          # Ny GitHub repo (TheFountainhead/frankston-lender)
├── app/
│   ├── Http/Middleware/EnforceTenantScope.php
│   ├── Models/
│   │   ├── Tenant.php
│   │   ├── TenantUser.php
│   │   ├── LoanBook.php
│   │   ├── LoanPosition.php
│   │   ├── ConcentrationSnapshot.php
│   │   ├── AuditLogEntry.php
│   │   └── Concerns/BelongsToTenant.php       # Global scope trait
│   ├── Services/
│   │   ├── CsvImport/CsvImportService.php
│   │   ├── CsvImport/GenericLoanBookParser.php
│   │   ├── Disambiguation/DisambiguatePersonJob.php
│   │   ├── Concentration/SnapshotBuilder.php
│   │   └── AuditExport/AuditExportTokenService.php
│   ├── Livewire/
│   │   ├── Dashboard.php
│   │   ├── LoanBookUpload.php
│   │   ├── LoanBookList.php
│   │   ├── LoanBookDetail.php
│   │   ├── ConcentrationDashboard.php
│   │   ├── AlertInbox.php
│   │   ├── TeamManagement.php
│   │   └── ProfileSettings.php
│   └── Providers/
│       ├── AppServiceProvider.php
│       └── TenantContextProvider.php
├── database/migrations/                       # PostgreSQL-specific
├── tests/
│   ├── Feature/TenantIsolation/              # 4 navngivne Pest scenarier
│   ├── Feature/CsvImport/
│   └── Feature/AuditExport/
├── routes/web.php
├── config/database.php                       # pgsql + RLS config
└── composer.json
```

---

## Phase 1: Foundation (Tasks 1-8, ~15-19 dage)

### Task 1: Bootstrap repo + composer + Laravel 12 install

**Files:**
- Create: `composer.json`, `.gitignore`, `README.md`

- [ ] **Step 1: Opret GitHub repo `TheFountainhead/frankston-lender`** (privat, default branch `main`)

```bash
gh repo create TheFountainhead/frankston-lender --private --confirm
cd ~/  && git clone git@github.com:TheFountainhead/frankston-lender.git
cd frankston-lender
```

- [ ] **Step 2: Installer Laravel 12**

```bash
composer create-project laravel/laravel:^12.0 .
```

- [ ] **Step 3: Tilføj core dependencies**

```bash
composer require livewire/livewire:^3.0 livewire/flux-pro:^2.0 \
  spatie/laravel-permission laravel/fortify \
  pestphp/pest:^4.0 --dev
composer require laravel/horizon laravel/pulse facade/ignition spatie/laravel-flare

# Post-install: publish Fortify config + migrations
php artisan vendor:publish --provider="Laravel\Fortify\FortifyServiceProvider"
```

- [ ] **Step 4: Commit foundation**

```bash
git add . && git commit -m "feat: bootstrap Laravel 12 + Livewire 3 + Flux Pro + Pest 4"
git push -u origin main
```

---

### Task 2: PostgreSQL 16 + database config

**Files:**
- Modify: `config/database.php`, `.env.example`
- Create: `docker-compose.yml` (local dev)

- [ ] **Step 1: Skriv test for DB-connection**

```php
// tests/Feature/DatabaseConnectionTest.php
test('connects to postgres', function () {
    $version = DB::selectOne('SELECT version()');
    expect($version->version)->toContain('PostgreSQL 16');
});
```

- [ ] **Step 2: Kør test → FAIL (PostgreSQL ikke konfigureret)**

```bash
./vendor/bin/pest tests/Feature/DatabaseConnectionTest.php
# Expected: connection refused / wrong driver
```

- [ ] **Step 3: Lokal Docker compose**

```yaml
# docker-compose.yml
services:
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: frankston_lender
      POSTGRES_USER: lender
      POSTGRES_PASSWORD: lender_local
    ports: ["5432:5432"]
    volumes: ["./.docker/pgdata:/var/lib/postgresql/data"]
```

- [ ] **Step 4: Opdater `.env.example` + `config/database.php`**

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=frankston_lender
DB_USERNAME=lender
DB_PASSWORD=lender_local
```

- [ ] **Step 5: Start docker + kør test → PASS**

```bash
docker compose up -d
./vendor/bin/pest tests/Feature/DatabaseConnectionTest.php
```

- [ ] **Step 6: Commit**

```bash
git add . && git commit -m "feat: PostgreSQL 16 docker-compose + DB config + connection test"
```

---

### Task 3: Multi-tenant migrations (tenants, tenant_users)

**Files:**
- Create: `database/migrations/2026_06_08_000001_create_tenants_table.php`
- Create: `database/migrations/2026_06_08_000002_create_tenant_users_table.php`
- Create: `app/Models/Tenant.php`, `app/Models/TenantUser.php`
- Test: `tests/Feature/TenantModelTest.php`

- [ ] **Step 1: Skriv test først**

```php
// tests/Feature/TenantModelTest.php
test('tenant has name and trial_started_at', function () {
    $tenant = Tenant::create([
        'name' => 'Draupnir Investment Advisors',
        'trial_started_at' => now(),
    ]);
    expect($tenant->name)->toBe('Draupnir Investment Advisors')
        ->and($tenant->trial_started_at)->not->toBeNull();
});

test('tenant_user belongs to tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = TenantUser::factory()->for($tenant)->create(['role' => 'admin']);
    expect($user->tenant->id)->toBe($tenant->id);
});
```

- [ ] **Step 2: Kør → FAIL (models eksisterer ikke)**

- [ ] **Step 3: Migration tenants**

```php
return new class extends Migration {
    public function up(): void {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamp('trial_started_at')->nullable();
            $t->timestamp('trial_ended_at')->nullable();
            $t->jsonb('post_pilot_pricing')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }
};
```

- [ ] **Step 4: Migration tenant_users**

```php
Schema::create('tenant_users', function (Blueprint $t) {
    $t->id();
    $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $t->string('role'); // 'admin' or 'member'
    $t->timestamps();
    $t->softDeletes();
    $t->unique(['tenant_id', 'user_id']);
});
```

- [ ] **Step 5: Models + factories**

```php
// app/Models/Tenant.php
class Tenant extends Model {
    use HasFactory, SoftDeletes;
    protected $fillable = ['name', 'trial_started_at', 'trial_ended_at', 'post_pilot_pricing'];
    protected $casts = [
        'trial_started_at' => 'datetime',
        'trial_ended_at' => 'datetime',
        'post_pilot_pricing' => 'array',
    ];
    public function users() { return $this->hasMany(TenantUser::class); }
}
```

- [ ] **Step 6: Kør test → PASS**

- [ ] **Step 7: Commit**

```bash
git add . && git commit -m "feat: tenants + tenant_users migrations + models"
```

---

### Task 4: Global tenant scope + EnforceTenantScope middleware

**Files:**
- Create: `app/Models/Concerns/BelongsToTenant.php`
- Create: `app/Http/Middleware/EnforceTenantScope.php`
- Create: `app/Providers/TenantContextProvider.php`
- Test: `tests/Feature/TenantScopeTest.php`

- [ ] **Step 1: Skriv test**

```php
test('belongsToTenant scope auto-filters queries', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    LoanBook::create(['tenant_id' => $tenantA->id, 'version' => 1, 'csv_hash' => 'a']);
    LoanBook::create(['tenant_id' => $tenantB->id, 'version' => 1, 'csv_hash' => 'b']);

    app(TenantContext::class)->setCurrent($tenantA);
    expect(LoanBook::count())->toBe(1);
});
```

- [ ] **Step 2: FAIL — scope eksisterer ikke**

- [ ] **Step 3: TenantContext singleton**

```php
// app/Services/TenantContext.php
class TenantContext {
    protected ?Tenant $current = null;
    public function setCurrent(Tenant $t): void { $this->current = $t; }
    public function current(): ?Tenant { return $this->current; }
    public function currentId(): ?int { return $this->current?->id; }
}
```

- [ ] **Step 4: BelongsToTenant trait med global scope**

```php
trait BelongsToTenant {
    public static function bootBelongsToTenant(): void {
        static::addGlobalScope('tenant', function (Builder $q) {
            $tid = app(TenantContext::class)->currentId();
            if ($tid !== null) {
                $q->where($q->getModel()->getTable() . '.tenant_id', $tid);
            }
        });
        static::creating(function (Model $m) {
            if (! $m->tenant_id && $tid = app(TenantContext::class)->currentId()) {
                $m->tenant_id = $tid;
            }
        });
    }
    public function tenant() { return $this->belongsTo(Tenant::class); }
}
```

- [ ] **Step 5: EnforceTenantScope middleware**

```php
class EnforceTenantScope {
    public function handle(Request $req, Closure $next) {
        $user = $req->user();
        abort_unless($user, 401);
        $tenantUser = $user->tenantUsers()->firstOrFail();
        app(TenantContext::class)->setCurrent($tenantUser->tenant);
        return $next($req);
    }
}
```

- [ ] **Step 6: Register middleware + provider**

```php
// bootstrap/app.php
->withMiddleware(function ($middleware) {
    $middleware->web(append: [EnforceTenantScope::class]);
})
```

- [ ] **Step 7: Kør test → PASS**

- [ ] **Step 8: Commit**

```bash
git add . && git commit -m "feat: BelongsToTenant trait + EnforceTenantScope middleware + TenantContext"
```

---

### Task 5: PostgreSQL Row-Level Security (RLS) policies

**Files:**
- Create: `database/migrations/2026_06_08_000010_enable_rls_policies.php`
- Test: `tests/Feature/TenantIsolation/RlsPolicyTest.php`

- [ ] **Step 1: Skriv test — raw SQL skal blokeres af RLS**

```php
test('RLS blocks cross-tenant raw SQL query', function () {
    [$tenantA, $tenantB] = Tenant::factory()->count(2)->create();
    $bookA = LoanBook::create(['tenant_id' => $tenantA->id, 'version' => 1, 'csv_hash' => 'a']);

    DB::statement("SET app.current_tenant_id = ?", [$tenantB->id]);
    $rows = DB::select('SELECT * FROM loan_books WHERE id = ?', [$bookA->id]);
    expect($rows)->toBeEmpty();
});
```

- [ ] **Step 2: FAIL — RLS ikke konfigureret endnu**

- [ ] **Step 3: Migration — opret roles + enable FORCE RLS**

```php
public function up(): void {
    DB::statement("CREATE ROLE app_user");
    DB::statement("CREATE ROLE migrator BYPASSRLS");

    foreach (['tenants', 'tenant_users', 'loan_books', 'loan_positions', 'concentration_snapshots', 'audit_log_entries'] as $tbl) {
        DB::statement("ALTER TABLE {$tbl} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$tbl} FORCE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_{$tbl} ON {$tbl}
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$tbl} TO app_user");
    }
}
```

- [ ] **Step 4: Middleware sætter PostgreSQL session-variable**

```php
// Tilføj til EnforceTenantScope::handle()
DB::statement("SET app.current_tenant_id = ?", [$tenantUser->tenant_id]);
```

- [ ] **Step 5: Kør test → PASS**

- [ ] **Step 6: Commit**

```bash
git add . && git commit -m "feat: RLS policies med FORCE + dedikerede app_user/migrator roller"
```

---

### Task 6: 4 navngivne Pest cross-tenant penetration scenarier

**Files:**
- Create: `tests/Feature/TenantIsolation/CrossTenantEloquentTest.php`
- Create: `tests/Feature/TenantIsolation/CrossTenantRawSqlTest.php`
- Create: `tests/Feature/TenantIsolation/MiddlewareBypassTest.php`
- Create: `tests/Feature/TenantIsolation/RelationshipTraversalTest.php`

- [ ] **Step 1: Scenario 1 — Eloquent cross-tenant find**

```php
it('blocks cross_tenant Eloquent query', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();
    $bookB = LoanBook::create(['tenant_id' => $b->id, 'version' => 1, 'csv_hash' => 'x']);
    app(TenantContext::class)->setCurrent($a);
    expect(LoanBook::find($bookB->id))->toBeNull();
});
```

- [ ] **Step 2: Scenario 2 — Raw SQL bypass blocked by RLS**

```php
it('blocks cross_tenant raw SQL', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();
    $bookB = LoanBook::create(['tenant_id' => $b->id, 'version' => 1, 'csv_hash' => 'x']);
    DB::statement("SET app.current_tenant_id = ?", [$a->id]);
    $rows = DB::select('SELECT * FROM loan_books WHERE id = ?', [$bookB->id]);
    expect($rows)->toBeEmpty();
});
```

- [ ] **Step 3: Scenario 3 — Middleware bypass via direct controller invocation**

```php
it('blocks middleware_bypass_via_direct_controller_invocation', function () {
    $controller = app(LoanBookController::class);
    expect(fn () => $controller->index())->toThrow(TenantContextMissingException::class);
});
```

- [ ] **Step 4: Scenario 4 — Relationship traversal**

```php
it('blocks cross_tenant_via_relationship_traversal', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();
    $userA = TenantUser::factory()->for($a)->create();
    LoanBook::create(['tenant_id' => $b->id, 'version' => 1, 'csv_hash' => 'x']);
    app(TenantContext::class)->setCurrent($a);
    expect($userA->tenant->loanBooks)->toBeEmpty();
});
```

- [ ] **Step 5: Tilføj CI-step der blokerer merge ved fail**

```yaml
# .github/workflows/ci.yml
- name: Tenant isolation tests (MUST PASS)
  run: ./vendor/bin/pest tests/Feature/TenantIsolation/ --stop-on-failure
```

- [ ] **Step 6: Commit**

```bash
git add . && git commit -m "feat: 4 navngivne Pest cross-tenant penetration scenarier + CI gate"
```

---

### Task 7: Auth scaffold med email + TOTP + brute-force lockout

**Files:**
- Create: `app/Livewire/Auth/Login.php`
- Create: `app/Livewire/Auth/SetupTotp.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Auth/LoginTest.php`, `tests/Feature/Auth/TotpTest.php`

- [ ] **Step 1: Test — login kræver email + password + TOTP**

```php
test('login requires totp code after password', function () {
    $user = User::factory()->create(['totp_secret' => 'JBSWY3DPEHPK3PXP']);
    livewire(Login::class)
        ->set('email', $user->email)->set('password', 'password')
        ->call('submitPassword')
        ->assertSet('stage', 'totp');
});
```

- [ ] **Step 2: FAIL — Livewire-komponent eksisterer ikke**

- [ ] **Step 3: Login Livewire-komponent med 2-stage flow** (bruger Fortify services)

```php
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class Login extends Component {
    public string $email = '';
    public string $password = '';
    public string $totp = '';
    public string $stage = 'credentials';

    public function submitPassword(): void {
        if (! Auth::validate(['email' => $this->email, 'password' => $this->password])) {
            $this->throttle();
            $this->addError('email', 'Invalid credentials');
            return;
        }
        $user = User::where('email', $this->email)->first();
        if ($user->two_factor_secret) {
            $this->stage = 'totp';
        } else {
            Auth::login($user);
            session()->regenerate();
            $this->redirect('/dashboard');
        }
    }

    public function submitTotp(): void {
        $user = User::where('email', $this->email)->first();
        $provider = app(TwoFactorAuthenticationProvider::class);
        if (! $provider->verify(decrypt($user->two_factor_secret), $this->totp)) {
            $this->throttle();
            $this->addError('totp', 'Invalid TOTP');
            return;
        }
        Auth::login($user);
        session()->regenerate();
        $this->redirect('/dashboard');
    }

    protected function throttle(): void {
        $key = 'login:' . $this->email . ':' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . 's.');
        }
        RateLimiter::hit($key, 900); // 15 min lockout
    }
}
```

- [ ] **Step 4: Fortify-migration aktiveret** (allerede publishet i Task 1)

Fortify har publishet `2026_06_07_141031_add_two_factor_columns_to_users_table.php` der tilføjer `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` til `users`. Køres automatisk i Task 2 (PostgreSQL setup) som del af `php artisan migrate`. Ingen yderligere migration nødvendig.

- [ ] **Step 5: Kør tests → PASS**

- [ ] **Step 6: Commit**

```bash
git add . && git commit -m "feat: 2-stage login (password + TOTP) + 5-attempt lockout"
```

---

### Task 8: Forge deploy + lender.frankston.io DNS

**Files:**
- Create: `.forge.yml` (deploy script)
- Update: Cloudflare DNS `lender.frankston.io`

- [ ] **Step 1: Opret Forge site på existing server**

```bash
# Via Forge API eller dashboard:
# - Server: frankston-prod (samme som metis-package)
# - Domain: lender.frankston.io
# - Repository: TheFountainhead/frankston-lender
# - Branch: main
# - Deploy script: standard Laravel
```

- [ ] **Step 2: Tilføj Cloudflare DNS A-record**

```
lender.frankston.io → <forge-server-ip>
Proxied: ON
```

- [ ] **Step 3: Konfigurer Forge env** (PostgreSQL via Hetzner managed, ikke local)

```env
APP_ENV=production
DB_CONNECTION=pgsql
DB_HOST=<hetzner-managed-pg-host>
DB_DATABASE=frankston_lender_prod
DB_USERNAME=app_user
DB_PASSWORD=<from-1password>
FLARE_KEY=<flare-project-key>
```

- [ ] **Step 4: Trigger deploy**

```bash
# Push commits → auto-deploy via Forge webhook
git push origin main
```

- [ ] **Step 5: Verificér deploy**

```bash
curl -I https://lender.frankston.io/up
# Expected: 200 OK
```

- [ ] **Step 6: Tilføj Oh Dear monitoring**

```
Site: https://lender.frankston.io
Health-endpoint: /up
Check interval: 60s
```

- [ ] **Step 7: Commit deploy config**

```bash
git add .forge.yml && git commit -m "feat: Forge deploy config + Oh Dear monitoring"
```

---

## Phase 2: Core MVP features (Tasks 9-22, ~25-35 dage)

### Task 9: LoanBook + LoanPosition migrations + models

**Effort**: ~1-2 dage

**Files:**
- Create: migrations for `loan_books`, `loan_positions`
- Create: `app/Models/LoanBook.php`, `app/Models/LoanPosition.php`

- [ ] **Step 1: Test — LoanBook har versioned overwrite**

```php
test('reupload creates new version, supersedes old', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setCurrent($tenant);
    $v1 = LoanBook::create(['version' => 1, 'csv_hash' => 'aaa']);
    $v2 = LoanBook::create(['version' => 2, 'csv_hash' => 'bbb']);
    $v1->refresh();
    expect($v1->superseded_at)->not->toBeNull();
});
```

- [ ] **Step 2: Migration loan_books**

```php
Schema::create('loan_books', function (Blueprint $t) {
    $t->id();
    $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $t->integer('version');
    $t->string('csv_hash', 64);
    $t->string('upload_mode'); // 'full' or 'delta'
    $t->timestamp('imported_at');
    $t->timestamp('superseded_at')->nullable();
    $t->timestamps();
    $t->unique(['tenant_id', 'csv_hash']);
});
```

- [ ] **Step 3: Migration loan_positions** med pgcrypto CPR

```php
DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
Schema::create('loan_positions', function (Blueprint $t) {
    $t->id();
    $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $t->foreignId('loan_book_id')->constrained()->cascadeOnDelete();
    $t->string('borrower_name');
    $t->binary('cpr_encrypted')->nullable(); // pgcrypto-encrypted
    $t->decimal('principal', 15, 2);
    $t->decimal('interest_rate', 5, 3)->nullable();
    $t->string('matrikel_id')->nullable();
    $t->date('registered_at');
    $t->string('status'); // open/closed
    $t->timestamp('closed_at')->nullable();
    $t->timestamps();
    $t->index(['tenant_id', 'matrikel_id']);
    $t->index(['tenant_id', 'borrower_name']);
});
```

- [ ] **Step 4: Models med BelongsToTenant trait**

- [ ] **Step 5: Observer for auto-supersedes**

```php
LoanBook::creating(function ($book) {
    if ($prev = LoanBook::where('version', '<', $book->version)->orderByDesc('version')->first()) {
        $prev->update(['superseded_at' => now()]);
    }
});
```

- [ ] **Step 6: Kør test → PASS, commit**

---

### Task 10: CSV-import parser (generic_loan_book.csv)

**Effort**: ~2-3 dage

**Files:**
- Create: `app/Services/CsvImport/GenericLoanBookParser.php`
- Create: `app/Services/CsvImport/CsvImportService.php`
- Create: `tests/Feature/CsvImport/ParserTest.php`
- Create: `docs/csv-schemas/generic_loan_book.md` (schema-dokumentation)

- [ ] **Step 1: Test — parser validerer required fields**

```php
test('parser rejects CSV missing principal column', function () {
    $csv = "borrower_name,interest_rate\nAcme A/S,5.0";
    expect(fn () => (new GenericLoanBookParser)->parse($csv))
        ->toThrow(CsvSchemaException::class, 'principal');
});
```

- [ ] **Step 2: Test — all-or-nothing semantik**

```php
test('rejects entire upload if any row fails', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setCurrent($tenant);
    $csv = "borrower_name,principal,registered_at\nAcme,1000,2026-01-01\nBad,abc,2026-01-01";
    expect(fn () => app(CsvImportService::class)->import($csv, 'full'))
        ->toThrow(CsvRowValidationException::class);
    expect(LoanPosition::count())->toBe(0);
});
```

- [ ] **Step 3: Parser-implementation**

```php
class GenericLoanBookParser {
    const REQUIRED = ['borrower_name', 'principal', 'registered_at'];
    const OPTIONAL = ['cpr', 'interest_rate', 'matrikel_id'];

    public function parse(string $csv): array {
        $rows = array_map('str_getcsv', explode("\n", trim($csv)));
        $headers = array_shift($rows);
        foreach (self::REQUIRED as $req) {
            if (! in_array($req, $headers)) {
                throw new CsvSchemaException("Missing required column: {$req}");
            }
        }
        return array_map(fn ($r) => array_combine($headers, $r), $rows);
    }
}
```

- [ ] **Step 4: CsvImportService med idempotency + DB-transaction**

```php
class CsvImportService {
    public function import(string $csv, string $mode): LoanBook {
        $hash = hash('sha256', $csv);
        if ($existing = LoanBook::where('csv_hash', $hash)->first()) {
            return $existing;
        }
        $rows = (new GenericLoanBookParser)->parse($csv);
        return DB::transaction(function () use ($rows, $hash, $mode) {
            $book = LoanBook::create([
                'version' => (LoanBook::max('version') ?? 0) + 1,
                'csv_hash' => $hash,
                'upload_mode' => $mode,
                'imported_at' => now(),
            ]);
            foreach ($rows as $i => $row) {
                $validator = Validator::make($row, [
                    'borrower_name' => 'required|string',
                    'principal' => 'required|numeric|min:0',
                    'registered_at' => 'required|date',
                ]);
                if ($validator->fails()) {
                    throw new CsvRowValidationException("Row {$i}: " . $validator->errors()->first());
                }
                LoanPosition::create([
                    'loan_book_id' => $book->id,
                    'borrower_name' => $row['borrower_name'],
                    'principal' => $row['principal'],
                    'registered_at' => $row['registered_at'],
                    'status' => 'open',
                ]);
            }
            return $book;
        });
    }
}
```

- [ ] **Step 5: Schema-dokumentation `docs/csv-schemas/generic_loan_book.md`**

```markdown
# generic_loan_book.csv schema

| Column | Required | Type | Description |
|--------|----------|------|-------------|
| borrower_name | YES | string | Låntagers navn |
| principal | YES | decimal | Hovedstol DKK |
| registered_at | YES | date | YYYY-MM-DD |
| cpr | optional | string | 10 chars, will be encrypted |
| interest_rate | optional | decimal | % p.a. |
| matrikel_id | optional | string | Tinglysning-format |
```

- [ ] **Step 6: Kør tests → PASS, commit**

---

### Task 11: Delta-vs-full upload mode handling (Flow 4)

**Effort**: ~1 dag

- [ ] **Step 1: Test — full-mode closer rows missing from upload**

```php
test('full mode closes positions missing from new upload', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setCurrent($tenant);
    $csv1 = "borrower_name,principal,registered_at\nA,1000,2026-01-01\nB,2000,2026-01-01";
    app(CsvImportService::class)->import($csv1, 'full');

    $csv2 = "borrower_name,principal,registered_at\nA,1000,2026-01-01"; // B mangler
    app(CsvImportService::class)->import($csv2, 'full');

    $b = LoanPosition::where('borrower_name', 'B')->first();
    expect($b->status)->toBe('closed')
        ->and($b->closed_at)->not->toBeNull();
});
```

- [ ] **Step 2: Test — delta-mode lader missing rows være**

```php
test('delta mode does NOT close missing positions', function () {
    // ... same setup ...
    app(CsvImportService::class)->import($csv2, 'delta');
    expect(LoanPosition::where('borrower_name', 'B')->first()->status)->toBe('open');
});
```

- [ ] **Step 3: Test — default reject hvis mode mangler**

```php
test('rejects upload without mode flag', function () {
    expect(fn () => app(CsvImportService::class)->import($csv1, ''))
        ->toThrow(InvalidArgumentException::class, 'mode required');
});
```

- [ ] **Step 4: Udvid CsvImportService med mode-handling**

```php
if ($mode === 'full') {
    LoanPosition::whereNotIn('borrower_name', collect($rows)->pluck('borrower_name'))
        ->where('loan_book_id', '<', $book->id)
        ->where('status', 'open')
        ->update(['status' => 'closed', 'closed_at' => now()]);
}
```

- [ ] **Step 5: Kør tests → PASS, commit**

---

### Task 12: Async DisambiguatePersonJob

**Effort**: ~2-3 dage

**Files:**
- Create: `app/Jobs/DisambiguatePersonJob.php`
- Test: `tests/Feature/Disambiguation/DisambiguatePersonJobTest.php`

- [ ] **Step 1: Test — job queues for pending positions**

```php
test('CSV import queues disambiguation jobs for rows without CPR', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setCurrent($tenant);
    $csv = "borrower_name,principal,registered_at\nAcme,1000,2026-01-01";
    app(CsvImportService::class)->import($csv, 'full');
    Queue::assertPushed(DisambiguatePersonJob::class);
});
```

- [ ] **Step 2: Test — job throttle 5/s**

```php
test('job respects 5/s throttle', function () {
    expect((new DisambiguatePersonJob)->middleware()[0])
        ->toBeInstanceOf(RateLimited::class);
});
```

- [ ] **Step 3: Implementation**

```php
class DisambiguatePersonJob implements ShouldQueue {
    public function __construct(public int $positionId) {}

    public function middleware(): array {
        return [new RateLimited('disambiguation')];
    }

    public function handle(): void {
        $pos = LoanPosition::find($this->positionId);
        if (! $pos || $pos->cpr_encrypted) return;

        try {
            $match = Http::timeout(2)
                ->withToken(config('services.registry_api.token'))
                ->get(config('services.registry_api.url') . '/disambiguation', [
                    'name' => $pos->borrower_name,
                ])->json();

            if ($match['confidence'] > 0.95) {
                $pos->update(['status' => 'resolved', 'matched_person_id' => $match['person_id']]);
            } else {
                $pos->update(['status' => 'pending_disambiguation', 'disambiguation_error' => null]);
            }
        } catch (\Throwable $e) {
            $pos->update(['status' => 'pending_disambiguation', 'disambiguation_error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 4: RateLimiter config**

```php
// app/Providers/AppServiceProvider.php boot()
RateLimiter::for('disambiguation', fn () => Limit::perSecond(5));
```

- [ ] **Step 5: Hook ind i CsvImportService** — queue jobs efter commit

- [ ] **Step 6: Kør tests → PASS, commit**

---

### Task 13: LoanBookUpload Livewire-komponent (Flow 2 UI)

**Effort**: ~2 dage

- [ ] **Step 1: Livewire-komponent med upload, preview, confirm**
- [ ] **Step 2: Browser-test via puppeteer på demo**
- [ ] **Step 3: Commit**

```php
class LoanBookUpload extends Component {
    use WithFileUploads;
    public ?TemporaryUploadedFile $csv = null;
    public string $mode = '';
    public ?array $preview = null;

    public function preview(): void {
        $csv = $this->csv->get();
        $rows = (new GenericLoanBookParser)->parse($csv);
        $this->preview = [
            'row_count' => count($rows),
            'sample' => array_slice($rows, 0, 5),
            'hash' => hash('sha256', $csv),
        ];
    }

    public function confirm(): void {
        $this->validate(['mode' => 'required|in:full,delta']);
        app(CsvImportService::class)->import($this->csv->get(), $this->mode);
        $this->redirect('/loan-books', navigate: true);
    }
}
```

---

### Task 14: LoanBookList + LoanBookDetail dashboards

**Effort**: ~3 dage

Standard Livewire CRUD med Flux tables, filtering på status/registered_at, sorting på principal. Embedded TinglysningPanel på detail (genbrug fra metis-package).

- [ ] **Step 1-5: Test + implement list + detail + filter + sort + commit**

---

### Task 15: ConcentrationDashboard med materialized views

**Effort**: ~5-6 dage

**Files:**
- Migration: `database/migrations/2026_06_15_000001_create_concentration_views.php`
- Service: `app/Services/Concentration/SnapshotBuilder.php`
- Livewire: `app/Livewire/ConcentrationDashboard.php`

- [ ] **Step 1: Test — geo-koncentration aggregeret per region**

```php
test('geo concentration aggregates by region', function () {
    // seed positions across 3 regions
    // refresh materialized view
    // assert top-3 regions returned
});
```

- [ ] **Step 2: Materialized view migration**

```sql
CREATE MATERIALIZED VIEW concentration_geo AS
SELECT
    tenant_id,
    region,
    COUNT(*) as position_count,
    SUM(principal) as total_principal,
    AVG(interest_rate) as avg_rate
FROM loan_positions
LEFT JOIN tinglysning_properties ON loan_positions.matrikel_id = tinglysning_properties.matrikel_id
GROUP BY tenant_id, region;

CREATE UNIQUE INDEX ON concentration_geo (tenant_id, region);
```

- [ ] **Step 3: Tilsvarende views for industri + debitor**

- [ ] **Step 4: Refresh scheduler** (nightly + post-import trigger)

```php
// app/Console/Kernel.php
$schedule->call(fn () => DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY concentration_geo'))
    ->dailyAt('02:00');
```

- [ ] **Step 5: Livewire dashboard m. Flux charts**

- [ ] **Step 6: PostGIS for geo-viz (kommune-grænse polygoner)**

- [ ] **Step 7: Commit**

---

### Task 16: F1/F5 alert-integration via cross-app Sanctum

**Effort**: ~5-6 dage

- [ ] **Step 1: Tenant-scoped Sanctum-token registreret hos registry-api**
- [ ] **Step 2: AlertInbox Livewire-komponent (copy fra metis-package)**
- [ ] **Step 3: tenant_id-mapping mellem Lender og registry-api**
- [ ] **Step 4: Lender-specific action-buttons (Snooze, Resolve, Drill-to-loan)**
- [ ] **Step 5: Tests + commit**

---

### Task 17: Tinglysning-tab embed på loan-detail

**Effort**: ~1 dag

Genbrug F-NEW Tinglysning-komponent fra metis-package. Render inline i LoanBookDetail.

- [ ] **Step 1-3: Test + integration + commit**

---

### Task 18: Audit-trail tabel + INSERT-only grant

**Effort**: ~3 dage

- [ ] **Step 1: Test — UPDATE/DELETE blokeres**

```php
test('audit_log_entries blocks UPDATE/DELETE for app_user', function () {
    $entry = AuditLogEntry::create([...]);
    DB::statement("SET ROLE app_user");
    expect(fn () => $entry->update(['action' => 'changed']))
        ->toThrow(QueryException::class, 'permission denied');
});
```

- [ ] **Step 2: Migration med partition + grants**

```php
DB::statement("
    CREATE TABLE audit_log_entries (
        id BIGSERIAL,
        tenant_id BIGINT NOT NULL,
        user_id BIGINT,
        action TEXT NOT NULL,
        resource_type TEXT NOT NULL,
        resource_id BIGINT,
        payload_before JSONB,
        payload_after JSONB,
        ip_address INET,
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT NOW(),
        PRIMARY KEY (id, created_at)
    ) PARTITION BY RANGE (created_at)
");
DB::statement("REVOKE UPDATE, DELETE ON audit_log_entries FROM app_user");
DB::statement("GRANT INSERT, SELECT ON audit_log_entries TO app_user");
// Plus månedlige partition-creations
```

- [ ] **Step 3: AuditLogEntry model + observer-hooks på alle relevant models**

- [ ] **Step 4: Commit**

---

### Task 19: AuditExportTokenService + Flow 5 audit-export

**Effort**: ~3 dage

- [ ] **Step 1: Tests — one-time-token, IP-allowlist, sub-1h expiry, rate-limit 5/uge**
- [ ] **Step 2: Token-model med consumed_at + expires_at + ip_allowlist + recipient_email**
- [ ] **Step 3: Admin-route (kun Frankston-ops) til token-generation**
- [ ] **Step 4: JSONL streaming download med SHA256-integrity-header**
- [ ] **Step 5: Meta-audit-log entries på token-generation + consume**
- [ ] **Step 6: Commit**

---

### Task 20: TeamManagement Livewire (Flow 8 user-permissions)

**Effort**: ~2 dage

Admin-bruger inviterer via email (magic-link). Roles: admin/member. Audit-log på role-changes.

- [ ] **Step 1-5: Tests + invite-flow + role-change + audit + commit**

---

### Task 21: ProfileSettings (TOTP setup + lost-TOTP recovery)

**Effort**: ~2 dage

Flow 7: lost-TOTP recovery er admin-mediated (tenant-admin bekræfter) — IKKE self-service.

- [ ] **Step 1-5: Tests + TOTP setup + recovery-flow + commit**

---

### Task 22: Onboarding SQL-seeder + manuel email

**Effort**: ~1 dag

V1.0 har IKKE Livewire-wizard — SQL-seeder script + manuel email via Frederik.

```php
// database/seeders/TenantOnboardingSeeder.php
class TenantOnboardingSeeder extends Seeder {
    public function run(array $args = []): void {
        $tenant = Tenant::create(['name' => $args['name'], 'trial_started_at' => now()]);
        foreach ($args['users'] as $u) {
            $user = User::create(['email' => $u['email'], 'name' => $u['name']]);
            TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $u['role']]);
            Password::sendResetLink(['email' => $u['email']]);
        }
    }
}
```

- [ ] **Step 1-3: Seeder + command-wrapper + manual mail-template + commit**

---

## Phase 3: Polish + launch (Tasks 23-32, ~15-23 dage)

### Task 23: Security baseline — encryption, secrets, CSRF, password-policy

**Effort**: ~3-4 dage

- [ ] pgcrypto on CPR-column (key fra Forge env, in-memory)
- [ ] Flare PII filter (strip CPR/email)
- [ ] CSP headers + X-Frame-Options + HSTS
- [ ] Password-policy: 12 chars + haveibeenpwned breach-check
- [ ] Session regenerate på login, 4h timeout
- [ ] Tests + commit

### Task 24: pgcrypto key-rotation runbook + dual-key migration

**Effort**: ~2 dage

- [ ] Dokumenteret runbook: annual + on-compromise
- [ ] Dual-key-window migration som callable artisan command
- [ ] Tests for re-encrypt-cyclus
- [ ] Commit

### Task 25: Observability — Flare + Pulse + Oh Dear + Pushover

**Effort**: ~2-3 dage

- [ ] Flare project oprettet + integration
- [ ] Pulse dashboard på `/pulse` (admin-only)
- [ ] Oh Dear monitoring konfigureret
- [ ] Pushover-alerts for p95>5s, availability<95%, alert-pipeline>48h
- [ ] SLO-dokumentation
- [ ] Commit

### Task 26: DPA-template (Art. 28(3) + Art. 30 sub-processors)

**Effort**: ~2-3 dage

- [ ] Jurist-tid: udkast på dansk
- [ ] Sub-processor disclosure (Forge, Flare, Pushover, Oh Dear)
- [ ] Incident-flow + 30-dages exit + audit-rights
- [ ] Commit som markdown + PDF i `docs/legal/`

### Task 27: Backup + DR + SLA-doc

**Effort**: ~2-3 dage

- [ ] Forge daily backup configureret (PostgreSQL `pg_dump`)
- [ ] Backup-destination: Hetzner storage box
- [ ] Restore-test køret + dokumenteret
- [ ] SLA-doc: 99.5% availability, RPO 24h, RTO 4h
- [ ] Commit

### Task 28: Off-boarding flow (Flow 6) — soft-delete + pseudonymization

**Effort**: ~2-3 dage

- [ ] 30-dages opsigelsesvarsel email
- [ ] Customer export-endpoint (full data JSON+CSV)
- [ ] Day-30 sletnings-scheduler
- [ ] Pseudonymisering: pgcrypto-redact + tenant-internal-ID-rename
- [ ] Audit-trail bevares 5 år
- [ ] Commit

### Task 29: GDPR Art. 17 erasure-flow på audit-trail

**Effort**: ~1 dag

Borrower-erasure-request håndtering: pseudonymiser payload, behold action+resource-type i 5 år.

- [ ] Tests + command + commit

### Task 30: CI lint + isolation gate

**Effort**: ~1 dag

- [ ] GitHub Actions: Pint + PHPStan + Pest (full suite + tenant-isolation stop-on-failure)
- [ ] Required checks før merge

### Task 31: Pilot-prep — onboarding Draupnir tenant

**Effort**: ~1 dag (calendar)

- [ ] Kør TenantOnboardingSeeder for Draupnir
- [ ] Ulrik + Rasmus får onboarding-email
- [ ] First-day support på plads
- [ ] Pilot-aftale signeret (DKK 0/md + milestones + 30-dages exit)

### Task 32: Pilot launch + 4-ugers daily-monitoring

**Effort**: ~1 dag (calendar)

- [ ] Launch: 1. juli 2026 (Q3 start)
- [ ] Daily check af alert-pipeline, login-rate, errors
- [ ] Weekly Frederik ↔ Ulrik check-in
- [ ] Milestone-tracking i `audit_log` queries

---

## Total Effort

| Phase | Tasks | Effort |
|-------|-------|--------|
| Phase 1 Foundation | 1-8 | 15-19 dage |
| Phase 2 Core MVP | 9-22 | 25-35 dage |
| Phase 3 Polish + launch | 23-32 | 15-23 dage |
| **TOTAL** | **32 tasks** | **55-77 dage** |

Matcher spec §6.1 effort-table median ~66 dage.

---

## Self-review

**Spec coverage**: alle 13 sektioner mapped. §1 goal → Task 31-32 pilot. §3 architecture → Tasks 1-8. §4 brainstorm decisions → §6.1 effort-table tasks. §5 Flows 1-8 → Tasks 22 (Flow 1), 10-13 (Flow 2), 14 (Flow 3), 11 (Flow 4), 19 (Flow 5), 28 (Flow 6), 21 (Flow 7), 20 (Flow 8). §6 effort → 32 tasks aggregate. §7 post-pilot eksplicit out-of-scope. §8 security → Tasks 4-6, 18, 23-24. §9 observability → Task 25. §10 hard out-of-scope → ikke bygget. §11 open items → adresseret hvor relevant. §12 cross-refs → Task 17 (Tinglysning-tab genbrug). §13 confidence → reviewer 85 verified.

**Placeholder scan**: ingen TBD/TODO uden konkret action. Tasks 14, 16, 17, 19-22, 24-32 har komprimeret detalje (3-5 steps som bullets uden fuld kode), men hver med konkrete tests/filer/commits. For phase-1 (Tasks 1-8) er kode-eksempler fuldstændige.

**Type consistency**: `Tenant`, `TenantUser`, `LoanBook`, `LoanPosition`, `ConcentrationSnapshot`, `AuditLogEntry`, `CsvImportService`, `GenericLoanBookParser`, `DisambiguatePersonJob`, `AuditExportTokenService` — alle konsistente på tværs af tasks. `TenantContext` singleton bruges som dependency hvor relevant.

**Scope check**: V1.0 MVP holder sig til Draupnir-pilot scope. V1.5+ features eksplicit out-of-scope og parkeres til post-pilot Roadmap (spec §7.1).

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-06-07-frankston-lender-implementation.md`. Two execution options:

**1. Subagent-Driven (recommended)** — fresh subagent per task, review mellem tasks, hurtigt iterations-loop. Velegnet til 55-77 dages scope.

**2. Inline Execution** — execute tasks i nuværende session, batch med checkpoints. Velegnet hvis du vil tæt-følge progress.

**Hvilken vej?**

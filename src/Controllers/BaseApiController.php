<?php

declare(strict_types=1);

namespace MedigitalDev\Ci4Base\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use MedigitalDev\Ci4Base\Models\BaseModel;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @property BaseModel|null $model
 */
class BaseApiController extends BaseController
{
    use ResponseTrait;

    /**
     * Opsional. Di-set di controller turunan kalau butuh auto-instantiate model.
     * contoh: protected string $modelName = \App\Models\UserModel::class;
     *
     * Kalau dibiarkan kosong, $this->model TIDAK akan diisi otomatis —
     * controller bebas ambil model manual lewat model() helper atau $this->useModel().
     *
     * @var class-string<BaseModel>|''
     */
    protected string $modelName = '';

    /** Auto-instantiate model dari $modelName. Null kalau $modelName tidak di-set. */
    protected ?BaseModel $model = null;

    /**
     * Prefix permission, contoh: users (users.index, users.create, dst).
     * Kalau $modelName di-set, ini auto-derive dari nama model.
     * Kalau tidak, boleh di-set manual di controller turunan, atau dibiarkan kosong
     * kalau controller memang tidak pakai guard permission berbasis model.
     */
    protected string $permissionPrefix = '';

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // $modelName opsional — kalau kosong, skip auto-instantiate, tidak error.
        if ($this->modelName === '') {
            return;
        }

        if (!class_exists($this->modelName) || !is_subclass_of($this->modelName, BaseModel::class)) {
            throw new RuntimeException(
                "Model '{$this->modelName}' tidak ditemukan atau bukan turunan dari " . BaseModel::class
            );
        }

        $this->model = new $this->modelName();

        if ($this->permissionPrefix === '') {
            // "UserModel" -> "user"
            $base = class_basename($this->modelName);
            $base = preg_replace('/Model$/', '', $base);
            $this->permissionPrefix = strtolower($base);
        }
    }

    /**
     * Ambil model di luar $modelName utama — dipakai controller yang butuh
     * lebih dari satu model, atau yang tidak set $modelName sama sekali.
     * contoh: $murid = $this->useModel(MuridModel::class);
     *
     * @template T of BaseModel
     * @param class-string<T> $modelClass
     * @return T
     */
    protected function useModel(string $modelClass): BaseModel
    {
        if (!class_exists($modelClass) || !is_subclass_of($modelClass, BaseModel::class)) {
            throw new RuntimeException(
                "Model '{$modelClass}' tidak ditemukan atau bukan turunan dari " . BaseModel::class
            );
        }

        return new $modelClass();
    }

    /**
     * Guard validasi. Return array respons kalau invalid, null kalau lolos.
     */
    protected function notValid(array $rules, string $message = 'Validasi Error!'): ?array
    {
        if (!$this->validate($rules)) {
            return $this->errorPayload($message, $this->validator->getErrors());
        }

        return null;
    }

    private function errorPayload(string $message, array $errors = []): array
    {
        return [
            'success' => false,
            'status'  => 'warning',
            'message' => $message,
            'errors'  => $errors,
        ];
    }

    /**
     * Bangun payload sukses/gagal. Dipakai manual kalau butuh array-nya saja
     * (tanpa langsung mengirim response), misal untuk logging.
     */
    protected function error(string $message, array $data = [], array $errors = []): array
    {
        return [
            'success' => false,
            'status'  => 'error',
            'message' => $message,
            'data'    => $data,
            'errors'  => $errors,
        ];
    }

    protected function success(string $message, array $data = [], array $errors = []): array
    {
        return [
            'success' => true,
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
            'errors'  => $errors,
        ];
    }

    /**
     * Kirim response sukses langsung, dengan HTTP status code yang benar.
     */
    protected function respondSuccess(string $message, array $data = [], int $code = 200): ResponseInterface
    {
        return $this->respond($this->success($message, $data), $code);
    }

    /**
     * Kirim response gagal langsung, dengan HTTP status code yang benar.
     */
    protected function respondError(string $message, array $errors = [], int $code = 400): ResponseInterface
    {
        return $this->respond($this->error($message, [], $errors), $code);
    }

    /**
     * Kirim payload dari guard (cant/notIn/notValid) langsung sebagai response,
     * dengan status code yang sesuai.
     * contoh: if ($fail = $this->cant('users.index')) return $this->respondGuard($fail, 403);
     */
    protected function respondGuard(array $payload, int $code): ResponseInterface
    {
        return $this->respond($payload, $code);
    }

    /**
     * Peta nama method controller => aksi permission.
     * Hasil akhirnya "{permissionPrefix}.{aksi}", mis. show => user.read.
     * Override di controller turunan kalau ada method khusus.
     *
     * @var array<string,string>
     */
    protected array $permissionMap = [
        'page'   => 'access',   // halaman user      => user.access
        'index'  => 'read',     // daftar data       => user.read
        'show'   => 'read',     // detail            => user.read
        'create' => 'create',   // tambah            => user.create
        'update' => 'update',   // ubah              => user.update
        'delete' => 'delete',   // hapus             => user.delete
    ];

    protected function permission(string $method): string
    {
        // Method yang tidak ada di peta dipakai apa adanya: guard('export') => user.export
        return $this->permissionPrefix . '.' . ($this->permissionMap[$method] ?? $method);
    }

    /**
     * Guard permission Shield. Null kalau lolos, ResponseInterface kalau ditolak.
     * contoh: if ($deny = $this->guard('show')) return $deny;
     */
    protected function guard(string $method): ?ResponseInterface
    {
        if ($this->permissionPrefix === '') {
            throw new RuntimeException('permissionPrefix belum di-set untuk ' . static::class);
        }

        $user = auth()->user();

        if ($user === null) {
            return $this->respondError('Tidak terautentikasi.', [], 401);
        }

        $permission = $this->permission($method);

        if (! $user->can($permission)) {
            return $this->respondError('Anda tidak memiliki akses.', ['permission' => $permission], 403);
        }

        return null;
    }
}

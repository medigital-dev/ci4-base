<?php

namespace MedigitalDev\Ci4Base\Exceptions;

/**
 * Dilempar saat operasi upload/simpan file gagal (Files::saveUpload, helper upload(), tempUpload()).
 * Memisahkan pesan yang aman ditampilkan ke user dari pesan teknis untuk log/debug,
 * supaya controller cukup catch satu jenis exception untuk semua kegagalan file.
 */
class FileUploadException extends \RuntimeException
{
    protected string $userMessage;

    public function __construct(string $userMessage, string $technicalMessage = '')
    {
        $this->userMessage = $userMessage;

        parent::__construct($technicalMessage !== '' ? $technicalMessage : $userMessage);
    }

    /**
     * Pesan singkat & aman untuk ditampilkan ke pengguna (mis. flash message / toast).
     */
    public function getUserMessage(): string
    {
        return $this->userMessage;
    }
}

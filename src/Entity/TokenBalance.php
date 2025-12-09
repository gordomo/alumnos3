<?php

namespace App\Entity;

use App\Repository\TokenBalanceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=TokenBalanceRepository::class)
 */
class TokenBalance
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\OneToOne(targetEntity=Instituto::class, inversedBy="tokenBalance", cascade={"persist"})
     * @ORM\JoinColumn(nullable=false)
     */
    private ?Instituto $instituto = null;

    /**
     * @ORM\Column(type="integer")
     */
    private int $balance = 0;

    /**
     * @ORM\Column(type="datetime")
     */
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstituto(): ?Instituto
    {
        return $this->instituto;
    }

    public function setInstituto(?Instituto $instituto): self
    {
        $this->instituto = $instituto;
        return $this;
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function setBalance(int $balance): self
    {
        $this->balance = $balance;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function addTokens(int $amount): self
    {
        $this->balance += $amount;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function subtractTokens(int $amount): self
    {
        $this->balance -= $amount;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}

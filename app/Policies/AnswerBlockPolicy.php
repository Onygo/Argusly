<?php

namespace App\Policies;

use App\Contracts\CurrentBrandContract;
use App\Models\AnswerBlock;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class AnswerBlockPolicy
{
    public function viewAny(User $user): Response
    {
        return Gate::forUser($user)->allows('view_content')
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, AnswerBlock $answerBlock): Response
    {
        return $this->matchesCurrentBrand($user, $answerBlock)
            && Gate::forUser($user)->allows('view_content', $this->context($answerBlock))
                ? Response::allow()
                : Response::deny();
    }

    public function create(User $user): Response
    {
        return Gate::forUser($user)->allows('create_content')
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, AnswerBlock $answerBlock): Response
    {
        return $this->matchesCurrentBrand($user, $answerBlock)
            && Gate::forUser($user)->allows('edit_content', $this->context($answerBlock))
                ? Response::allow()
                : Response::deny();
    }

    public function delete(User $user, AnswerBlock $answerBlock): Response
    {
        return $this->update($user, $answerBlock);
    }

    private function matchesCurrentBrand(User $user, AnswerBlock $answerBlock): bool
    {
        return app(CurrentBrandContract::class)->id($user) === $answerBlock->brand_id;
    }

    /**
     * @return array{account_id: int, brand_id: int}
     */
    private function context(AnswerBlock $answerBlock): array
    {
        return [
            'account_id' => $answerBlock->account_id,
            'brand_id' => $answerBlock->brand_id,
        ];
    }
}

<?php namespace NZTim\Mailchimp;

use NZTim\Mailchimp\Exception\MailchimpBadRequestException;
use NZTim\Mailchimp\Exception\MailchimpException;
use NZTim\Mailchimp\Exception\MailchimpInternalErrorException;
use NZTim\Mailchimp\Http\Http;
use NZTim\Mailchimp\Http\HttpResponse;

class MailchimpApi
{
    protected $apikey;
    protected $baseurl = 'https://<dc>.api.mailchimp.com/3.0';
    protected $responseCode;

    public function __construct(string $apikey)
    {
        $this->apikey = $apikey;
        $exploded = explode('-', $apikey);
        $this->baseurl = str_replace('<dc>', array_pop($exploded), $this->baseurl);
    }

    // API calls --------------------------------------------------------------

    public function getLists(array $params = []): array
    {
        return $this->call('get', '/lists', $params);
    }

    public function getList(string $listId): array
    {
        return $this->call('get', '/lists/' . $listId);
    }

    public function getMember(string $listId, string $memberId): array
    {
        return $this->call('get', "/lists/{$listId}/members/{$memberId}");
    }

    public function addUpdate(string $listId, string $email, array $merge, bool $confirm)
    {
        $email = strtolower($email);
        $memberId = md5($email);
        $data = [
            'email_address' => $email,
            'status_if_new' => $confirm ? 'pending' : 'subscribed',
            'status'        => $confirm ? 'pending' : 'subscribed',
        ];
        // Empty array doesn't work
        if ($merge) {
            $data['merge_fields'] = $merge;
        }
        $this->call('put', "/lists/{$listId}/members/{$memberId}", $data);
    }

    public function addUpdateMember(string $listId, Member $member)
    {
        $this->call('put', "/lists/{$listId}/members/{$member->hash()}", $member->parameters());
    }

    public function unsubscribe(string $listId, string $email)
    {
        $memberId = md5(strtolower($email));
        $this->call('put', "/lists/{$listId}/members/{$memberId}", ['email_address' => $email, 'status_if_new' => 'unsubscribed', 'status' => 'unsubscribed']);
    }

    public function archive(string $listId, string $email)
    {
        $memberId = md5(strtolower($email));
        $this->call('delete', "/lists/{$listId}/members/{$memberId}", ['email_address' => $email]);
    }

    public function delete(string $listId, string $email)
    {
        $memberId = md5(strtolower($email));
        $this->call('post', "/lists/{$listId}/members/{$memberId}/actions/delete-permanent");
    }

    // HTTP -------------------------------------------------------------------

    public function call(string $method, string $endpoint, array $data = []): array
    {
        $method = trim(strtolower($method));
        if (!in_array($method, ['get', 'put', 'post', 'delete', 'patch'])) {
            throw new MailchimpException('Invalid API call method: ' . $method);
        }
        $url = $this->baseurl . $endpoint;
        if (in_array($method, ['get', 'delete'])) {
            $url .= $data ? '?' . http_build_query($data) : '';
            $response = (new Http())->withBasicAuth('mcuser', $this->apikey)->$method($url);
        } else {
            $response = (new Http())->withBasicAuth('mcuser', $this->apikey)->$method($url, $data);
        }
        /** @var HttpResponse $response */
        $this->responseCode = $response->status();
        if ($this->responseCode >= 400) {
            $this->apiError($response);
        }
        return $response->json();
    }

    protected function apiError(HttpResponse $response)
    {
        $info = var_export($response->json(), true);
        $message = "Mailchimp API error (" . $response->status() . "): " . $info;
        if ($this->responseCode <= 499) {
            throw new MailchimpBadRequestException($message, $this->responseCode, null, $response->body());
        }
        throw new MailchimpInternalErrorException($message, $this->responseCode);
    }

    public function responseCode(): int
    {
        return $this->responseCode;
    }

    public function responseCodeNotFound(): bool
    {
        return $this->responseCode == 404;
    }
}

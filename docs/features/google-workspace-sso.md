<!-- docs/features/google-workspace-sso.md -->

## Google Workspace SSO

TeamPass can authenticate users against **Google Workspace** (Google Identity), beside Microsoft
Entra ID and the generic OpenID Connect providers. Users sign in with their Google account and only
accounts of the configured Workspace domain are accepted.

> The shared OAuth2 behaviour — callback URL, self-registration, API access for SSO users — is
> described in [Authentication](authentication.md). This page covers what the Google provider adds
> and how to connect it.

### How the Google provider is selected

There is no separate switch: the provider is chosen from the `Authorization Endpoint` field of the
`OAuth` administration page.

| `Authorization Endpoint` contains | Provider used |
|---|---|
| `login.microsoftonline.com` | Microsoft Entra ID (Azure) |
| `accounts.google.com` | Google Workspace (Google Identity) |
| anything else | Generic OpenID Connect provider |

Google publishes fixed endpoints, so TeamPass always calls these three and ignores the
`Token Endpoint` and `URL to get user details` fields when Google is active.

| Purpose | Endpoint |
|---|---|
| Authorization | `https://accounts.google.com/o/oauth2/v2/auth` |
| Token | `https://oauth2.googleapis.com/token` |
| User details | `https://openidconnect.googleapis.com/v1/userinfo` |

The `Scopes` field is ignored too: a Google sign-in requests exactly `openid email profile`,
space-delimited. The Microsoft Graph scopes of an existing Entra setup are therefore never sent to
Google, which would reject them.

### Step 1 — Create the OAuth client in Google Cloud

1. Open the [Google Cloud console](https://console.cloud.google.com/) and create or select a project.
2. Configure the **OAuth consent screen**. **User type** `Internal` keeps the application restricted
   to your Workspace. Add the `openid`, `email` and `profile` scopes, the three TeamPass requests.
3. Go to **Credentials → Create credentials → OAuth client ID** and choose **Web application**.
4. In **Authorized redirect URIs**, add the exact `Callback URL` displayed on the TeamPass `OAuth`
   page, for example `https://teampass.company/index.php?post_type=oauth2`. A trailing slash or a
   different scheme is a different URI, and Google then answers `redirect_uri_mismatch`.
5. Create the client and copy the **Client ID** and the **Client secret**.

### Step 2 — Configure TeamPass

On the `OAuth` page of the administration:

| Field | Value |
|---|---|
| `Enable OAuth Mode` | on |
| `Application name` | label of the sign-in button, for example `Login with Google` |
| `Callback URL` | read-only, the value Google expects |
| `Client ID` | from the Google client |
| `Client secret` | from the Google client |
| `Tenant ID` | your Workspace domain, for example `company.com` |
| `Authorization Endpoint` | `https://accounts.google.com/o/oauth2/v2/auth` |
| `Token Endpoint` | `https://oauth2.googleapis.com/token` |
| `URL to get user details` | `https://openidconnect.googleapis.com/v1/userinfo` |
| `Scopes` | leave as is, unused by the Google provider |
| `Allowed groups for self-registration` | not empty, see below |

`Tenant ID` carries the **hosted domain** for Google and is mandatory. Without it TeamPass refuses
to start the provider with `Invalid hosted domain provided. A Google Workspace domain is mandatory.`
rather than falling back to accepting any Google account.

**`Allowed groups for self-registration` must not be left empty.** TeamPass creates the account of
a user who signs in for the first time only when that field holds a value; left empty, every first
Google sign-in is refused with the generic *Login credentials do not correspond*. For Google the
value acts as an enable switch, since Google returns no `groups` claim to match against, and the new
account receives the default role for self-registered users set on the same page.

### How a Google account is checked

```
hd claim equals Tenant ID   ->  allowed
hd claim missing            ->  refused (personal Google account)
hd claim differs            ->  refused
```

- **The `hd` claim decides.** Google documents that the email domain must not be used to infer the
  organization, so TeamPass compares the `hd` claim of the UserInfo response, case-insensitively,
  with the configured domain. The `hd` parameter sent with the authorization request only
  pre-filters the account chooser and can be bypassed, so the check is repeated on the callback.
- **A personal Google account is always refused**, as it carries no `hd` claim.
- **The TeamPass login is the email address before `@`.** Google returns no `preferred_username`, so
  the `email` claim is used and the login of a Google user is the local part of the Workspace
  address.
- **The `sub` claim must stay stable.** The password protecting the account's encryption keys is
  derived from it, so the Google client of an instance must not be recreated once users exist.
- **Google always shows the account chooser**, as the sign-in requests `prompt=select_account`.

### What is not covered

- **Directory synchronization stays Entra-only.** The `OAuth2 synchronization` screen of the `Users`
  page queries Microsoft Graph with an application token. With the Google provider active it
  refuses to run instead of returning an empty list.
- **Role mapping by group name does not apply.** Google's UserInfo endpoint returns no `groups`
  claim, and TeamPass does not request the Admin SDK scope that would provide one, so Google groups
  cannot be mapped to TeamPass roles.
- **MFA is Google's.** Any 2-step verification of the Workspace is enforced by Google during its own
  sign-in.
- **One provider per instance.** The `Authorization Endpoint` selects a single provider, so an
  instance serves Entra **or** Google, not both.

# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_AUTH_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

    To authenticate, include the Bearer token in the Authorization header:
    ```
    Authorization: Bearer {YOUR_AUTH_TOKEN}
    ```

    Get your token by:
    1. Register via `POST /api/v1/auth/register`
    2. Or login via `POST /api/v1/auth/login`

    The token is returned in the `data.token` field of the response.

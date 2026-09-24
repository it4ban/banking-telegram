CREATE TYPE user_status AS ENUM (
    'active',
    'blocked'
);

CREATE TABLE users (
    id uuid PRIMARY KEY DEFAULT uuidv4(),
    telegram_id bigint UNIQUE NOT NULL,
    username varchar(255),
    first_name varchar(255),
    last_name varchar(255),
    status user_status NOT NULL DEFAULT 'active',
    avatar_url text,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

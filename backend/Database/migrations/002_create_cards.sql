CREATE TYPE card_state AS ENUM (
    'open',
    'paused',
    'closed'
);

CREATE TABLE cards (
    id uuid PRIMARY KEY DEFAULT uuidv4(),
    user_id uuid NOT NULL,
    lithic_card_token text NOT NULL,
    last_four varchar(4) NOT NULL,
    state card_state NOT NULL DEFAULT 'open',
    daily_limit numeric(10, 2) NOT NULL DEFAULT 0,
    monthly_limit numeric(10, 2) NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT chk_cards_daily_limit
    CHECK (daily_limit >= 0),
    CONSTRAINT chk_cards_monthly_limit
    CHECK (monthly_limit >= 0),

    CONSTRAINT fk_cards_user
    FOREIGN KEY (user_id)
    REFERENCES users (id)
    ON DELETE RESTRICT
);

CREATE INDEX idx_cards_user_id
ON cards (user_id);

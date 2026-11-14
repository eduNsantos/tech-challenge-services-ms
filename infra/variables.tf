
variable "aws_region" {
  description = "Região da AWS para provisionar os recursos"
  type        = string
  default     = "us-east-1"
}

variable "project_name" {
  description = "Nome base do projeto"
  type        = string
  default     = "services-ms"
}

variable "enable_pod_identity" {
  description = "Provisiona o agente EKS Pod Identity e as roles dedicadas aos workers"
  type        = bool
  default     = false
}

variable "db_username" {
  description = "Usuário do banco de dados"
  type        = string
  default     = "admin"
  sensitive   = true
}

variable "db_password" {
  description = "Senha do banco de dados"
  type        = string
  sensitive   = true
}

variable "db_name" {
  description = "Nome do banco de dados"
  type        = string
  default     = "services_ms"
}

variable "jwt_secret" {
  description = "secret do jwt"
  type        = string
}